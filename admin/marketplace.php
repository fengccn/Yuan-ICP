<?php
require_once __DIR__.'/../includes/bootstrap.php';

// 权限检查
check_admin_auth();
if (!has_permission('system.plugins') && !has_permission('system.themes')) {
    handle_error('您没有权限访问应用市场', true, 403);
}

// 处理安装请求逻辑 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) { 
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) { 
        $_SESSION['flash_error'] = '无效的请求，请刷新重试。'; 
    } else { 
        $action = $_POST['action'] ?? ''; 
        $url = $_POST['download_url'] ?? ''; 
        $type = $_POST['type'] ?? ''; 
        $tab = ($type === 'theme') ? 'themes' : 'plugins'; // 记录当前 Tab 

        try { 
            if ($action === 'install') { 
                $tempDir = __DIR__ . '/../uploads/temp/'; 
                if (!is_dir($tempDir)) mkdir($tempDir, 0755, true); 
                $tempFile = $tempDir . uniqid() . '.zip'; 
                
                // 1. 下载 
                $content = @file_get_contents($url); 
                if (!$content) throw new Exception('无法连接到下载服务器，请检查网络'); 
                file_put_contents($tempFile, $content); 

                // 2. 解压 
                $zip = new ZipArchive; 
                if ($zip->open($tempFile) === TRUE) { 
                    $extractDir = $tempDir . uniqid() . '_extract/'; 
                    mkdir($extractDir); 
                    $zip->extractTo($extractDir); 
                    $zip->close(); 
                    unlink($tempFile); 

                    // 3. 文件夹“脱壳”逻辑 (GitHub 的 ZIP 里面总是包着一层 repo-name-master) 
                    $innerFiles = array_diff(scandir($extractDir), ['.', '..']); 
                    $realSourceDir = $extractDir; 
                    if (count($innerFiles) === 1) { 
                        $firstFile = reset($innerFiles); 
                        if (is_dir($extractDir . $firstFile)) { 
                            $realSourceDir = $extractDir . $firstFile . '/'; 
                        } 
                    } 

                    // 4. 识别标识符并确定安装路径 
                    $identifier = ''; 
                    if ($type === 'plugin') { 
                        // 尝试从 manifest.json 读取 ID 
                        if (file_exists($realSourceDir . 'manifest.json')) { 
                            $m = json_decode(file_get_contents($realSourceDir . 'manifest.json'), true); 
                            $identifier = $m['id'] ?? ''; 
                        } 
                        // 降级尝试从文件夹名猜测 (去掉开头的 plugin-) 
                        if (empty($identifier)) { 
                             $dirname = basename(rtrim($realSourceDir, '/')); 
                             $identifier = str_replace('plugin-', '', explode('-', $dirname)[0]); 
                        } 
                        $installPath = __DIR__ . '/../plugins/' . $identifier; 
                    } else { 
                        // 主题逻辑 
                        if (file_exists($realSourceDir . 'theme.json')) { 
                            $m = json_decode(file_get_contents($realSourceDir . 'theme.json'), true); 
                            $identifier = $m['id'] ?? $m['name'] ?? ''; 
                        } 
                        if (empty($identifier)) $identifier = basename(rtrim($realSourceDir, '/')); 
                        $installPath = __DIR__ . '/../themes/' . $identifier; 
                    } 

                    if (empty($identifier)) throw new Exception('无法识别该应用的唯一标识符'); 

                    // 5. 移动文件（如果已存在则覆盖） 
                    if (is_dir($installPath)) { 
                        // 简单覆盖前清理旧文件 
                        $it = new RecursiveDirectoryIterator($installPath, RecursiveDirectoryIterator::SKIP_DOTS); 
                        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST); 
                        foreach($files as $file) { 
                            if ($file->isDir()) rmdir($file->getRealPath()); 
                            else unlink($file->getRealPath()); 
                        } 
                        rmdir($installPath); 
                    } 
                    
                    rename(rtrim($realSourceDir, '/'), $installPath); 
                    
                    // 6. 如果是插件，注册到数据库 
                    if ($type === 'plugin') { 
                        PluginManager::installFromIdentifier($identifier); 
                    } 

                    $_SESSION['flash_message'] = '安装成功！'; 
                } else { 
                    throw new Exception('ZIP 文件损坏'); 
                } 
            } 
        } catch (Exception $e) { 
             $_SESSION['flash_error'] = '错误：' . $e->getMessage(); 
        } 
        
        // 跳转回市场并保持当前的 Tab 
        header('Location: marketplace.php?tab=' . $tab); 
        exit; 
    } 
}

$plugins = MarketplaceManager::getPlugins();
$themes = MarketplaceManager::getThemes();

// 获取已安装状态
$installedPlugins = array_column(PluginManager::getAllPlugins(), 'identifier');
$installedThemes = array_keys(ThemeManager::getAvailableThemes());

$activeTab = $_GET['tab'] ?? 'plugins';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>应用市场 - Yuan-ICP</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- 侧边栏 -->
            <div class="col-md-2 p-0">
                <?php include __DIR__.'/../includes/admin_sidebar.php'; ?>
            </div>
            
            <!-- 主内容区 -->
            <div class="col-md-10 main-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="mb-0">应用市场</h2>
                    <div class="text-muted small">
                        <i class="fas fa-sync fa-spin me-1"></i> 自动同步自 GitHub 官方源
                    </div>
                </div>

                <?php if (isset($_SESSION['flash_message'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['flash_message']; unset($_SESSION['flash_message']); ?></div>
                <?php endif; ?>

                <!-- 子导航 -->
                <ul class="nav nav-tabs mb-4">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'plugins' ? 'active' : ''; ?>" href="?tab=plugins">
                            <i class="fas fa-puzzle-piece me-1"></i>插件市场
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'themes' ? 'active' : ''; ?>" href="?tab=themes">
                            <i class="fas fa-palette me-1"></i>主题市场
                        </a>
                    </li>
                </ul>

                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                    <?php
                    $currentList = ($activeTab === 'themes') ? $themes : $plugins;
                    $typeLabel = ($activeTab === 'themes') ? 'theme' : 'plugin';
                    $installedList = ($activeTab === 'themes') ? $installedThemes : $installedPlugins;

                    if (empty($currentList)): ?>
                        <div class="col-12 text-center py-5">
                            <div class="card shadow-none bg-transparent">
                                <div class="card-body">
                                    <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">该分类暂无可用应用，请稍后再试。</p>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($currentList as $app): ?>
                            <div class="col">
                                <div class="card h-100 shadow-sm border-0">
                                    <?php if ($activeTab === 'themes' && !empty($app['screenshot'])): ?>
                                        <img src="<?php echo htmlspecialchars($app['screenshot']); ?>" class="card-img-top" style="height: 180px; object-fit: cover;" alt="Preview">
                                    <?php endif; ?>
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <h5 class="card-title fw-bold"><?php echo htmlspecialchars($app['name']); ?></h5>
                                            <span class="badge bg-light text-dark">v<?php echo htmlspecialchars($app['version']); ?></span>
                                        </div>
                                        <p class="text-muted small mb-2">
                                            <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($app['author']); ?>
                                            <span class="ms-2"><i class="fas fa-star text-warning me-1"></i><?php echo $app['stars']; ?></span>
                                        </p>
                                        <p class="card-text text-secondary small"><?php echo htmlspecialchars($app['description']); ?></p>
                                    </div>
                                    <div class="card-footer bg-white border-0 pb-3">
                                        <?php if (in_array($app['id'], $installedList)): ?>
                                            <button class="btn btn-secondary btn-sm w-100" disabled>
                                                <i class="fas fa-check-circle me-1"></i>已安装
                                            </button>
                                        <?php else: ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="action" value="install">
                                                <input type="hidden" name="type" value="<?php echo $typeLabel; ?>">
                                                <input type="hidden" name="download_url" value="<?php echo htmlspecialchars($app['download_url']); ?>">
                                                <button type="submit" class="btn btn-primary btn-sm w-100 shadow-sm">
                                                    <i class="fas fa-download me-1"></i>一键安装
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
