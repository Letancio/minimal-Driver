<?php
session_start();
$db_file = 'database.sqlite';

// Conexão com banco SQLite
$pdo = new PDO("sqlite:$db_file");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Criar tabelas
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    email TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    fullname TEXT NOT NULL,
    avatar TEXT DEFAULT 'https://ui-avatars.com/api/?background=6366f1&color=fff&rounded=true'
);

CREATE TABLE IF NOT EXISTS folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    icon TEXT DEFAULT 'folder',
    color TEXT DEFAULT '#6366f1',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    folder_id INTEGER NOT NULL,
    filename TEXT NOT NULL,
    original_name TEXT NOT NULL,
    filesize INTEGER NOT NULL,
    filetype TEXT NOT NULL,
    uploaded_by TEXT NOT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE
);
");

// Lógica das rotas
$action = $_GET['action'] ?? 'login';

if (isset($_SESSION['user_id']) && $action === 'login') {
    $action = 'dashboard';
}

switch ($action) {
    case 'register':
        handleRegister($pdo);
        break;
    case 'login':
        handleLogin($pdo);
        break;
    case 'logout':
        handleLogout();
        break;
    case 'dashboard':
        handleDashboard($pdo);
        break;
    case 'create-folder':
        handleCreateFolder($pdo);
        break;
    case 'upload':
        handleUpload($pdo);
        break;
    case 'delete-file':
        handleDeleteFile($pdo);
        break;
    case 'delete-folder':
        handleDeleteFolder($pdo);
        break;
    case 'download':
        handleDownload($pdo);
        break;
    default:
        handleLogin($pdo);
}
exit;

// ------------- FUNÇÕES -------------

function handleRegister($pdo) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $fullname = trim($_POST['fullname']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, fullname) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $email, $password, $fullname]);
            
            $_SESSION['message'] = "Conta criada com sucesso! Faça login.";
            header("Location: index.php?action=login");
            exit;
        } catch (PDOException $e) {
            $error = "Usuário ou e-mail já existe.";
        }
    }
    renderPage('register', ['error' => $error ?? null], null);
}

function handleLogin($pdo) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = $_POST['username'];
        $password = $_POST['password'];
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['avatar'] = $user['avatar'];
            header("Location: index.php?action=dashboard");
            exit;
        } else {
            $error = "Usuário ou senha inválidos.";
        }
    }
    renderPage('login', ['error' => $error ?? null], null);
}

function handleLogout() {
    session_destroy();
    header("Location: index.php");
    exit;
}

function handleDashboard($pdo) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit;
    }
    
    $folderId = $_GET['folder'] ?? null;
    $currentFolder = null;
    $files = [];
    
    // Buscar todas as pastas do usuário
    $stmt = $pdo->prepare("SELECT * FROM folders WHERE user_id = ? ORDER BY name");
    $stmt->execute([$_SESSION['user_id']]);
    $folders = $stmt->fetchAll();
    
    // Se não houver pastas, criar algumas padrão
    if (count($folders) === 0) {
        $defaultFolders = ['Documentos', 'Imagens', 'Vídeos', 'Músicas', 'Trabalho', 'Pessoal'];
        $insertStmt = $pdo->prepare("INSERT INTO folders (user_id, name) VALUES (?, ?)");
        foreach ($defaultFolders as $folder) {
            $insertStmt->execute([$_SESSION['user_id'], $folder]);
        }
        
        // Recarregar pastas
        $stmt = $pdo->prepare("SELECT * FROM folders WHERE user_id = ? ORDER BY name");
        $stmt->execute([$_SESSION['user_id']]);
        $folders = $stmt->fetchAll();
    }
    
    // Se não houver pasta selecionada, pegar a primeira
    if (!$folderId && count($folders) > 0) {
        $folderId = $folders[0]['id'];
    }
    
    if ($folderId) {
        $stmt = $pdo->prepare("SELECT * FROM folders WHERE id = ? AND user_id = ?");
        $stmt->execute([$folderId, $_SESSION['user_id']]);
        $currentFolder = $stmt->fetch();
        
        if ($currentFolder) {
            // Buscar arquivos da pasta
            $stmt = $pdo->prepare("SELECT * FROM files WHERE folder_id = ? AND user_id = ? ORDER BY uploaded_at DESC");
            $stmt->execute([$folderId, $_SESSION['user_id']]);
            $files = $stmt->fetchAll();
        }
    }
    
    // Buscar arquivos recentes (últimos 5)
    $stmt = $pdo->prepare("SELECT f.*, fd.name as folder_name FROM files f JOIN folders fd ON f.folder_id = fd.id WHERE f.user_id = ? ORDER BY f.uploaded_at DESC LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $recentFiles = $stmt->fetchAll();
    
    // Calcular espaço usado do servidor (pasta uploads)
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $totalServerSpace = disk_total_space($uploadDir);
    $freeServerSpace = disk_free_space($uploadDir);
    $usedServerSpace = $totalServerSpace - $freeServerSpace;
    
    // Calcular espaço usado pelos arquivos do usuário
    $stmt = $pdo->prepare("SELECT SUM(filesize) as total FROM files WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userUsedSpace = $stmt->fetch()['total'] ?? 0;
    
    $userUsedPercent = ($totalServerSpace > 0) ? ($userUsedSpace / $totalServerSpace) * 100 : 0;
    
    // Para contar arquivos por pasta, vamos passar o PDO para o renderPage
    renderPage('dashboard', [
        'folders' => $folders,
        'currentFolder' => $currentFolder,
        'files' => $files,
        'recentFiles' => $recentFiles,
        'totalServerSpace' => $totalServerSpace,
        'usedServerSpace' => $usedServerSpace,
        'userUsedSpace' => $userUsedSpace,
        'userUsedPercent' => $userUsedPercent,
        'message' => $_SESSION['message'] ?? null,
        'pdo' => $pdo
    ]);
    unset($_SESSION['message']);
}

function handleCreateFolder($pdo) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autorizado']);
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name']);
        
        // Verificar se pasta já existe
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM folders WHERE user_id = ? AND name = ?");
        $stmt->execute([$_SESSION['user_id'], $name]);
        if ($stmt->fetchColumn() > 0) {
            if ($_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                echo json_encode(['error' => 'Pasta já existe']);
                exit;
            }
        }
        
        $stmt = $pdo->prepare("INSERT INTO folders (user_id, name) VALUES (?, ?)");
        $stmt->execute([$_SESSION['user_id'], $name]);
        
        if ($_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            echo json_encode(['success' => true, 'folder_id' => $pdo->lastInsertId(), 'name' => $name]);
            exit;
        }
    }
    header("Location: index.php?action=dashboard");
    exit;
}

function handleUpload($pdo) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo "Não autorizado";
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
        $folderId = $_POST['folder_id'] ?? 0;
        $file = $_FILES['file'];
        $uploadDir = __DIR__ . '/uploads/';
        
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $uniqueName = uniqid() . '_' . basename($file['name']);
        $destination = $uploadDir . $uniqueName;
        $fileType = strtoupper(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $stmt = $pdo->prepare("INSERT INTO files (user_id, folder_id, filename, original_name, filesize, filetype, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['user_id'],
                $folderId,
                $uniqueName,
                $file['name'],
                $file['size'],
                $fileType,
                $_SESSION['fullname']
            ]);
            $_SESSION['message'] = "Arquivo enviado com sucesso!";
        } else {
            $_SESSION['message'] = "Erro ao fazer upload.";
        }
    }
    header("Location: index.php?action=dashboard&folder=" . $folderId);
    exit;
}

function handleDeleteFile($pdo) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit;
    }
    
    $fileId = $_GET['id'] ?? 0;
    $folderId = $_GET['folder'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $_SESSION['user_id']]);
    $file = $stmt->fetch();
    
    if ($file) {
        $filePath = __DIR__ . '/uploads/' . $file['filename'];
        if (file_exists($filePath)) unlink($filePath);
        
        $stmt = $pdo->prepare("DELETE FROM files WHERE id = ?");
        $stmt->execute([$fileId]);
        $_SESSION['message'] = "Arquivo excluído.";
    }
    header("Location: index.php?action=dashboard&folder=" . $folderId);
    exit;
}

function handleDeleteFolder($pdo) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit;
    }
    
    $folderId = $_GET['id'] ?? 0;
    
    // Pegar todos os arquivos da pasta para deletar do disco
    $stmt = $pdo->prepare("SELECT filename FROM files WHERE folder_id = ? AND user_id = ?");
    $stmt->execute([$folderId, $_SESSION['user_id']]);
    $files = $stmt->fetchAll();
    
    foreach ($files as $file) {
        $filePath = __DIR__ . '/uploads/' . $file['filename'];
        if (file_exists($filePath)) unlink($filePath);
    }
    
    $stmt = $pdo->prepare("DELETE FROM folders WHERE id = ? AND user_id = ?");
    $stmt->execute([$folderId, $_SESSION['user_id']]);
    $_SESSION['message'] = "Pasta excluída com seus arquivos.";
    
    header("Location: index.php?action=dashboard");
    exit;
}

function handleDownload($pdo) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: index.php");
        exit;
    }
    
    $fileId = $_GET['id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ? AND user_id = ?");
    $stmt->execute([$fileId, $_SESSION['user_id']]);
    $file = $stmt->fetch();
    
    if ($file) {
        $filePath = __DIR__ . '/uploads/' . $file['filename'];
        if (file_exists($filePath)) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        }
    }
    $_SESSION['message'] = "Arquivo não encontrado.";
    header("Location: index.php?action=dashboard");
    exit;
}

// Função para contar arquivos da pasta
function countFilesInFolder($pdo, $folderId, $userId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM files WHERE folder_id = ? AND user_id = ?");
    $stmt->execute([$folderId, $userId]);
    return $stmt->fetchColumn();
}

// ---------- RENDERIZAÇÃO ----------
function renderPage($view, $data = [], $pdo = null) {
    extract($data);
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Cloudnest - Gestão de Arquivos</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
            * { font-family: 'Inter', sans-serif; }
            .sidebar-item:hover { background: #f3f4f6; transform: translateX(4px); transition: all 0.2s; }
            .folder-card:hover { background: #f9fafb; transform: translateY(-2px); transition: all 0.2s; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
            .file-item:hover { background: #f9fafb; transition: all 0.2s; }
            .modal { transition: opacity 0.3s ease; }
        </style>
    </head>
    <body class="bg-gray-50">
        <?php if ($view === 'login' || $view === 'register'): ?>
            <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-indigo-500 via-purple-500 to-pink-500">
                <div class="bg-white rounded-2xl shadow-2xl p-8 max-w-md w-full mx-4">
                    <div class="text-center mb-8">
                        <i class="fas fa-cloud-upload-alt text-5xl text-indigo-600"></i>
                        <h1 class="text-3xl font-bold mt-3 text-gray-800">Cloudnest</h1>
                        <p class="text-gray-500 mt-2">Sua plataforma de arquivos na nuvem</p>
                    </div>
                    
                    <?php if (isset($error)): ?>
                        <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-3 mb-4 rounded-lg text-sm"><?= $error ?></div>
                    <?php endif; ?>
                    
                    <?php if ($view === 'login'): ?>
                        <form method="POST">
                            <div class="mb-4">
                                <label class="block text-gray-700 text-sm font-semibold mb-2">Usuário ou E-mail</label>
                                <input type="text" name="username" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                            </div>
                            <div class="mb-6">
                                <label class="block text-gray-700 text-sm font-semibold mb-2">Senha</label>
                                <input type="password" name="password" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                            </div>
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg transition duration-200">
                                Entrar
                            </button>
                        </form>
                        <div class="text-center mt-4">
                            <p class="text-gray-600">Não tem conta? <a href="?action=register" class="text-indigo-600 hover:underline font-semibold">Cadastre-se</a></p>
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label class="block text-gray-700 text-sm font-semibold mb-2">Nome completo</label>
                                <input type="text" name="fullname" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <div class="mb-3">
                                <label class="block text-gray-700 text-sm font-semibold mb-2">Usuário</label>
                                <input type="text" name="username" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <div class="mb-3">
                                <label class="block text-gray-700 text-sm font-semibold mb-2">E-mail</label>
                                <input type="email" name="email" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <div class="mb-6">
                                <label class="block text-gray-700 text-sm font-semibold mb-2">Senha</label>
                                <input type="password" name="password" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg transition">
                                Criar Conta
                            </button>
                        </form>
                        <div class="text-center mt-4">
                            <p class="text-gray-600">Já tem conta? <a href="?action=login" class="text-indigo-600 hover:underline font-semibold">Faça login</a></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($view === 'dashboard'): ?>
            <div class="flex h-screen overflow-hidden">
                <!-- Sidebar -->
                <aside class="w-72 bg-white border-r border-gray-200 flex flex-col overflow-y-auto">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex items-center space-x-2">
                            <i class="fas fa-cloud-upload-alt text-2xl text-indigo-600"></i>
                            <h1 class="text-2xl font-bold text-gray-800">Cloudnest</h1>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Sistema de Arquivos</p>
                    </div>
                    
                    <div class="flex-1 py-6">
                        <div class="px-4 mb-6">
                            <div class="bg-indigo-50 rounded-xl p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-semibold text-gray-700">Meu Armazenamento</span>
                                    <span class="text-xs text-gray-500"><?= formatFileSize($userUsedSpace) ?> / <?= formatFileSize($totalServerSpace) ?></span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-indigo-600 h-2 rounded-full" style="width: <?= min($userUsedPercent, 100) ?>%"></div>
                                </div>
                                <p class="text-xs text-gray-500 mt-2">
                                    <i class="fas fa-database"></i> Espaço usado no servidor: <?= formatFileSize($usedServerSpace) ?> de <?= formatFileSize($totalServerSpace) ?>
                                </p>
                            </div>
                        </div>
                        
                        <nav class="space-y-1 px-3">
                            <a href="?action=dashboard" class="sidebar-item flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100">
                                <i class="fas fa-th-large w-5"></i>
                                <span>Todos os Arquivos</span>
                            </a>
                            <a href="#" class="sidebar-item flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100">
                                <i class="fas fa-image w-5"></i>
                                <span>Fotos</span>
                            </a>
                            <a href="#" class="sidebar-item flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100">
                                <i class="fas fa-heart w-5"></i>
                                <span>Favoritos</span>
                            </a>
                            <a href="#" class="sidebar-item flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100">
                                <i class="fas fa-share-alt w-5"></i>
                                <span>Arquivos Compartilhados</span>
                            </a>
                            <a href="#" class="sidebar-item flex items-center space-x-3 px-3 py-2 rounded-lg text-gray-700 hover:bg-gray-100">
                                <i class="fas fa-trash w-5"></i>
                                <span>Lixeira</span>
                            </a>
                            <div class="pt-4 mt-4 border-t border-gray-200">
                                <a href="?action=logout" class="sidebar-item flex items-center space-x-3 px-3 py-2 rounded-lg text-red-600 hover:bg-red-50">
                                    <i class="fas fa-sign-out-alt w-5"></i>
                                    <span>Sair</span>
                                </a>
                            </div>
                        </nav>
                    </div>
                </aside>
                
                <!-- Main Content -->
                <main class="flex-1 overflow-y-auto">
                    <div class="p-8">
                        <!-- Header -->
                        <div class="flex justify-between items-start mb-8">
                            <div>
                                <h2 class="text-3xl font-bold text-gray-800">Bem-vindo de volta, <?= htmlspecialchars($_SESSION['fullname']) ?></h2>
                                <p class="text-gray-500 mt-1">Continue sua atividade no dashboard.</p>
                            </div>
                            <div class="flex items-center space-x-3">
                                <img src="<?= $_SESSION['avatar'] ?>&name=<?= urlencode($_SESSION['fullname']) ?>" class="w-10 h-10 rounded-full">
                                <div>
                                    <p class="text-sm font-semibold"><?= htmlspecialchars($_SESSION['fullname']) ?></p>
                                    <p class="text-xs text-gray-500"><?= htmlspecialchars($_SESSION['username']) ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (isset($message)): ?>
                            <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-3 mb-4 rounded-lg text-sm"><?= $message ?></div>
                        <?php endif; ?>
                        
                        <!-- Minhas Pastas -->
                        <div class="mb-8">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="text-xl font-bold text-gray-800">
                                    <i class="fas fa-folder-open text-indigo-600"></i> Minhas Pastas
                                </h3>
                                <button onclick="openCreateFolderModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm transition flex items-center gap-2">
                                    <i class="fas fa-plus"></i> Nova Pasta
                                </button>
                            </div>
                            
                            <?php if (count($folders) === 0): ?>
                                <div class="text-center py-12 bg-white rounded-xl border border-gray-200">
                                    <i class="fas fa-folder-open text-6xl text-gray-300 mb-3"></i>
                                    <p class="text-gray-400">Você ainda não tem pastas</p>
                                    <button onclick="openCreateFolderModal()" class="mt-3 text-indigo-600 hover:text-indigo-700 font-semibold">
                                        Criar primeira pasta
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                                    <?php foreach ($folders as $folder): ?>
                                        <?php 
                                            // Contar arquivos na pasta usando a função global
                                            $fileCount = countFilesInFolder($pdo, $folder['id'], $_SESSION['user_id']);
                                        ?>
                                        <a href="?action=dashboard&folder=<?= $folder['id'] ?>" class="folder-card block p-4 bg-white rounded-xl border border-gray-200 hover:shadow-md transition relative group">
                                            <div class="relative">
                                                <i class="fas fa-folder text-4xl <?= $currentFolder && $currentFolder['id'] == $folder['id'] ? 'text-indigo-600' : 'text-yellow-500' ?>"></i>
                                                <?php if ($fileCount > 0): ?>
                                                    <span class="absolute -top-2 -right-2 bg-indigo-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center"><?= $fileCount ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="font-medium text-gray-800 mt-2 text-sm truncate"><?= htmlspecialchars($folder['name']) ?></p>
                                            <button onclick="event.preventDefault(); deleteFolder(<?= $folder['id'] ?>)" class="absolute top-2 right-2 text-red-400 hover:text-red-600 opacity-0 group-hover:opacity-100 transition">
                                                <i class="fas fa-trash text-xs"></i>
                                            </button>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Arquivos da Pasta Atual -->
                        <?php if ($currentFolder): ?>
                            <div class="mb-8">
                                <div class="flex justify-between items-center mb-4">
                                    <h3 class="text-xl font-bold text-gray-800">
                                        <i class="fas fa-folder text-indigo-600"></i> <?= htmlspecialchars($currentFolder['name']) ?>
                                    </h3>
                                    <form action="?action=upload" method="POST" enctype="multipart/form-data" class="inline">
                                        <input type="hidden" name="folder_id" value="<?= $currentFolder['id'] ?>">
                                        <label class="cursor-pointer bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm transition flex items-center gap-2">
                                            <i class="fas fa-upload"></i> Enviar Arquivo
                                            <input type="file" name="file" required class="hidden" onchange="this.form.submit()">
                                        </label>
                                    </form>
                                </div>
                                
                                <?php if (count($files) === 0): ?>
                                    <div class="text-center py-12 bg-white rounded-xl border border-gray-200">
                                        <i class="fas fa-inbox text-6xl text-gray-300 mb-3"></i>
                                        <p class="text-gray-400">Nenhum arquivo nesta pasta</p>
                                        <p class="text-sm text-gray-400 mt-1">Clique em "Enviar Arquivo" para adicionar arquivos</p>
                                    </div>
                                <?php else: ?>
                                    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                                        <table class="w-full">
                                            <thead class="bg-gray-50 border-b border-gray-200">
                                                <tr>
                                                    <th class="text-left p-4 text-sm font-semibold text-gray-600">Arquivo</th>
                                                    <th class="text-left p-4 text-sm font-semibold text-gray-600">Tamanho</th>
                                                    <th class="text-left p-4 text-sm font-semibold text-gray-600">Enviado por</th>
                                                    <th class="text-left p-4 text-sm font-semibold text-gray-600">Data</th>
                                                    <th class="text-left p-4 text-sm font-semibold text-gray-600">Ações</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($files as $file): ?>
                                                    <tr class="file-item border-b border-gray-100 hover:bg-gray-50">
                                                        <td class="p-4">
                                                            <div class="flex items-center gap-3">
                                                                <i class="fas <?= getFileIcon($file['filetype']) ?> text-2xl"></i>
                                                                <span class="font-medium text-gray-800"><?= htmlspecialchars($file['original_name']) ?></span>
                                                            </div>
                                                        </td>
                                                        <td class="p-4 text-sm text-gray-600"><?= formatFileSize($file['filesize']) ?></td>
                                                        <td class="p-4 text-sm text-gray-600"><?= htmlspecialchars($file['uploaded_by']) ?></td>
                                                        <td class="p-4 text-sm text-gray-500"><?= date('d/m/Y H:i', strtotime($file['uploaded_at'])) ?></td>
                                                        <td class="p-4">
                                                            <div class="flex gap-2">
                                                                <a href="?action=download&id=<?= $file['id'] ?>" class="text-blue-600 hover:text-blue-800" title="Download">
                                                                    <i class="fas fa-download"></i>
                                                                </a>
                                                                <a href="?action=delete-file&id=<?= $file['id'] ?>&folder=<?= $currentFolder['id'] ?>" onclick="return confirm('Excluir este arquivo?')" class="text-red-600 hover:text-red-800" title="Excluir">
                                                                    <i class="fas fa-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    <tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Arquivos Recentes -->
                        <?php if (count($recentFiles) > 0): ?>
                        <div>
                            <h3 class="text-xl font-bold text-gray-800 mb-4">
                                <i class="fas fa-clock text-indigo-600"></i> Arquivos Recentes
                            </h3>
                            <div class="space-y-2">
                                <?php foreach ($recentFiles as $file): ?>
                                    <div class="flex justify-between items-center p-3 bg-white rounded-lg border border-gray-200">
                                        <div class="flex items-center gap-3">
                                            <i class="fas <?= getFileIcon($file['filetype']) ?> text-xl"></i>
                                            <div>
                                                <p class="font-medium text-gray-800"><?= htmlspecialchars($file['original_name']) ?></p>
                                                <p class="text-xs text-gray-500"><?= htmlspecialchars($file['folder_name']) ?> • <?= formatFileSize($file['filesize']) ?></p>
                                            </div>
                                        </div>
                                        <a href="?action=download&id=<?= $file['id'] ?>" class="text-indigo-600 hover:text-indigo-800">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </main>
            </div>
            
            <!-- Modal Criar Pasta -->
            <div id="createFolderModal" class="modal fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
                <div class="bg-white rounded-xl p-6 w-96">
                    <h3 class="text-xl font-bold mb-4">Criar Nova Pasta</h3>
                    <input type="text" id="folderName" placeholder="Nome da pasta" class="w-full px-4 py-2 border border-gray-300 rounded-lg mb-4 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <div class="flex gap-3">
                        <button onclick="createFolder()" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white py-2 rounded-lg transition">Criar</button>
                        <button onclick="closeCreateFolderModal()" class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 py-2 rounded-lg transition">Cancelar</button>
                    </div>
                </div>
            </div>
            
            <script>
                function openCreateFolderModal() {
                    document.getElementById('createFolderModal').classList.remove('hidden');
                    document.getElementById('createFolderModal').classList.add('flex');
                    document.getElementById('folderName').value = '';
                }
                
                function closeCreateFolderModal() {
                    document.getElementById('createFolderModal').classList.add('hidden');
                    document.getElementById('createFolderModal').classList.remove('flex');
                }
                
                function createFolder() {
                    const name = document.getElementById('folderName').value;
                    if (!name.trim()) {
                        alert('Por favor, insira um nome para a pasta');
                        return;
                    }
                    
                    fetch('?action=create-folder', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: 'name=' + encodeURIComponent(name)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else if (data.error) {
                            alert(data.error);
                        }
                    })
                    .catch(error => {
                        console.error('Erro:', error);
                        alert('Erro ao criar pasta');
                    });
                }
                
                function deleteFolder(folderId) {
                    if (confirm('Tem certeza que deseja excluir esta pasta e todos os arquivos dentro dela?')) {
                        window.location.href = '?action=delete-folder&id=' + folderId;
                    }
                }
            </script>
        <?php endif; ?>
    </body>
    </html>
    <?php
}

function getFileIcon($type) {
    $icons = [
        'PDF' => 'fa-file-pdf text-red-500',
        'JPG' => 'fa-file-image text-green-500',
        'JPEG' => 'fa-file-image text-green-500',
        'PNG' => 'fa-file-image text-green-500',
        'GIF' => 'fa-file-image text-green-500',
        'ZIP' => 'fa-file-archive text-yellow-500',
        'RAR' => 'fa-file-archive text-yellow-500',
        'DOC' => 'fa-file-word text-blue-500',
        'DOCX' => 'fa-file-word text-blue-500',
        'XLS' => 'fa-file-excel text-green-600',
        'XLSX' => 'fa-file-excel text-green-600',
        'PPT' => 'fa-file-powerpoint text-orange-500',
        'PPTX' => 'fa-file-powerpoint text-orange-500',
        'MP4' => 'fa-file-video text-purple-500',
        'MP3' => 'fa-file-audio text-pink-500',
        'TXT' => 'fa-file-alt text-gray-500'
    ];
    return $icons[$type] ?? 'fa-file-alt text-gray-500';
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}
?>