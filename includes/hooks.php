<?php
/**
 * Yuan-ICP 插件钩子系统
 * 
 * 提供插件与核心系统的交互机制
 */

class PluginHooks {
    private static $hooks = [];
    
    /**
     * 添加钩子
     * @param string $name 钩子名称
     * @param callable $callback 回调函数
     * @param int $priority 优先级(数字越大优先级越高)
     */
    public static function add($name, $callback, $priority = 10) {
        if (!isset(self::$hooks[$name])) {
            self::$hooks[$name] = [];
        }
        
        self::$hooks[$name][] = [
            'callback' => $callback,
            'priority' => $priority
        ];
    }
    
    /**
     * 执行钩子
     * @param string $name 钩子名称
     * @param mixed $data 传递给回调函数的数据
     * @return mixed 处理后的数据
     */
    public static function run($name, $data = null) {
        if (!isset(self::$hooks[$name])) {
            return $data;
        }
        
        // 按优先级排序
        usort(self::$hooks[$name], function($a, $b) {
            return $b['priority'] - $a['priority'];
        });
        
        foreach (self::$hooks[$name] as $hook) {
            $data = call_user_func($hook['callback'], $data);
        }
        
        return $data;
    }
    
    /**
     * 获取所有已注册的钩子
     * @return array
     */
    public static function getAll() {
        return self::$hooks;
    }
}

/**
 * 增强的插件钩子系统
 */
class EnhancedPluginHooks {
    private static $adminMenus = [];
    private static $settingsPages = [];
    
    /**
     * 注册后台菜单项
     * @param string $id 菜单项ID
     * @param string $title 菜单标题
     * @param string $url 菜单链接
     * @param string $icon 菜单图标
     * @param string $parent 父菜单ID（可选）
     * @param int $priority 优先级
     */
    public static function registerAdminMenu($id, $title, $url, $icon = 'fas fa-cog', $parent = null, $priority = 10) {
        self::$adminMenus[$id] = [
            'id' => $id,
            'title' => $title,
            'url' => $url,
            'icon' => $icon,
            'parent' => $parent,
            'priority' => $priority
        ];
    }
    
    /**
     * 注册设置页面
     * @param string $id 页面ID
     * @param string $title 页面标题
     * @param string $tab 标签页ID
     * @param callable $callback 渲染回调函数
     * @param int $priority 优先级
     */
    public static function registerSettingsPage($id, $title, $tab, $callback, $priority = 10) {
        self::$settingsPages[$id] = [
            'id' => $id,
            'title' => $title,
            'tab' => $tab,
            'callback' => $callback,
            'priority' => $priority
        ];
    }
    
    /**
     * 获取所有注册的后台菜单
     * @return array
     */
    public static function getAdminMenus() {
        // 按优先级排序
        uasort(self::$adminMenus, function($a, $b) {
            return $b['priority'] - $a['priority'];
        });
        return self::$adminMenus;
    }
    
    /**
     * 获取所有注册的设置页面
     * @return array
     */
    public static function getSettingsPages() {
        // 按优先级排序
        uasort(self::$settingsPages, function($a, $b) {
            return $b['priority'] - $a['priority'];
        });
        return self::$settingsPages;
    }
    
    /**
     * 渲染设置页面
     * @param string $tab 当前标签页
     */
    public static function renderSettingsPage($tab) {
        $pages = self::getSettingsPages();
        foreach ($pages as $page) {
            if ($page['tab'] === $tab && is_callable($page['callback'])) {
                call_user_func($page['callback']);
                return;
            }
        }
    }
}

/**
 * 插件管理类
 */
class PluginManager {
    /**
     * Helper function to call a function within a plugin's main file.
     */
    private static function call_plugin_function($identifier, $function_name) {
        $mainFile = __DIR__ . '/../plugins/' . $identifier . '/plugin.php';
        if (file_exists($mainFile)) {
            // Include the file to make sure the function is available
            require_once $mainFile;
            if (function_exists($function_name)) {
                call_user_func($function_name);
            }
        }
    }

    public static function activate($identifier) {
        self::call_plugin_function($identifier, $identifier . '_activate');
        $db = db();
        $stmt = $db->prepare("UPDATE plugins SET is_active = 1 WHERE identifier = ?");
        return $stmt->execute([$identifier]);
    }

    public static function deactivate($identifier) {
        self::call_plugin_function($identifier, $identifier . '_deactivate');
        $db = db();
        $stmt = $db->prepare("UPDATE plugins SET is_active = 0 WHERE identifier = ?");
        return $stmt->execute([$identifier]);
    }

    public static function uninstall($identifier) {
        // 1. Run the plugin's own uninstallation logic (e.g., drop tables)
        self::call_plugin_function($identifier, $identifier . '_uninstall');

        // 2. Delete the plugin's files
        $pluginDir = __DIR__.'/../plugins/'.$identifier;
        if (is_dir($pluginDir)) {
            // A simple recursive delete function
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
            }
            rmdir($pluginDir);
        }

        // 3. Remove from the database
        $db = db();
        $stmt = $db->prepare("DELETE FROM plugins WHERE identifier = ?");
        return $stmt->execute([$identifier]);
    }
    
    /**
     * 获取所有已安装插件
     * @return array
     */
    public static function getInstalledPlugins() {
        $db = db(); // 确保获取数据库连接
        if (!$db) {
            return []; // 如果数据库连接失败，返回空数组
        }
        
        $stmt = $db->query("SELECT * FROM plugins WHERE is_active = 1");
        return $stmt->fetchAll();
    }

    /**
     * 获取单个插件的信息（从文件读取）
     * @param string $identifier
     * @return array|false
     */
    public static function getPluginInfo($identifier) {
        $pluginDir = __DIR__ . '/../plugins/' . $identifier;
        $mainFile = $pluginDir . '/plugin.php';
        
        // --- 自动修复嵌套目录结构 (Auto-fix nested directory) ---
        // 如果找不到 plugin.php，检查是否在子目录中
        if (!file_exists($mainFile) && is_dir($pluginDir)) {
             $files = scandir($pluginDir);
             $subDirs = [];
             foreach ($files as $f) {
                 if ($f !== '.' && $f !== '..' && is_dir($pluginDir . '/' . $f)) {
                     $subDirs[] = $f;
                 }
             }
             
             // 如果只有一个子目录，且该子目录看似包含插件文件
             if (count($subDirs) === 1) {
                 $subDirName = $subDirs[0];
                 $nestedDir = $pluginDir . '/' . $subDirName;
                 
                 // 检查子目录里是否有 plugin.php 或 manifest.json
                 if (file_exists($nestedDir . '/plugin.php') || file_exists($nestedDir . '/manifest.json')) {
                     // 1. 先重命名子目录，防止文件名冲突
                     $tempDir = $pluginDir . '/_temp_' . uniqid();
                     rename($nestedDir, $tempDir);
                     
                     // 2. 将内容移动到上一级
                     $innerItems = array_diff(scandir($tempDir), ['.', '..']);
                     foreach ($innerItems as $item) {
                         rename($tempDir . '/' . $item, $pluginDir . '/' . $item);
                     }
                     
                     // 3. 删除临时目录
                     rmdir($tempDir);
                 }
             }
        }
        
        $manifestFile = $pluginDir . '/manifest.json';
        
        if (!file_exists($mainFile)) return false;

        $pluginInfo = [];

        // 优先从 plugin.php 读取，因为它是运行时的真实数据
        // 模拟 include 环境获取变量
        $_GET['plugin_info'] = true;
        // 使用 output buffering 捕获可能产生的输出，防止污染页面
        ob_start();
        try {
            $infoFromPHP = include $mainFile;
        } catch (Exception $e) {
            $infoFromPHP = false;
        }
        ob_end_clean();
        
        unset($_GET['plugin_info']);
        if (is_array($infoFromPHP)) {
            $pluginInfo = array_merge($pluginInfo, $infoFromPHP);
        }
        
        // 如果 plugin.php 没有提供足够信息，再尝试 manifest.json (作为补充)
        if (file_exists($manifestFile)) {
            $manifestData = json_decode(file_get_contents($manifestFile), true);
            if (is_array($manifestData)) {
                 // 只补充缺失的字段，不覆盖 plugin.php 的数据
                 foreach ($manifestData as $key => $value) {
                     if (empty($pluginInfo[$key])) {
                         $pluginInfo[$key] = $value;
                     }
                 }
            }
        }
        
        // 最终验证：如果缺少必要信息，尝试使用默认值
        if (empty($pluginInfo['name'])) {
            $pluginInfo['name'] = $identifier; // 默认使用目录名
        }
        if (empty($pluginInfo['identifier'])) {
            $pluginInfo['identifier'] = $identifier; // 默认使用目录名
        }
        if (empty($pluginInfo['version'])) {
            $pluginInfo['version'] = '1.0.0';
        }

        return $pluginInfo;
    }

    /**
     * 获取所有插件（包括未激活的）
     * 同时也负责发现新插件并注册到数据库
     * @return array
     */
    public static function getAllPlugins() {
        $plugins = [];
        $dbPlugins = [];
        
        // 1. 获取数据库中的插件状态 (主要是 is_active)
        $db = db();
        if ($db) {
            try {
                $rows = $db->query("SELECT * FROM plugins")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $dbPlugins[$row['identifier']] = $row;
                }
            } catch (Exception $e) {
                // DB error, ignore
            }
        }

        // 2. 扫描插件目录
        $pluginDir = __DIR__ . '/../plugins/';
        if (is_dir($pluginDir)) {
            $dirs = scandir($pluginDir);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                $fullPath = $pluginDir . $dir;
                if (!is_dir($fullPath)) continue;

                // 尝试读取并同步插件信息
                // self::installFromIdentifier($dir); // 不再需要单独调用，getPluginInfo + 逻辑合并即可
                // 但为了保持 DB 同步，我们可以在获取信息后顺便更新 DB
                
                $info = self::getPluginInfo($dir);
                if ($info) {
                    // 同步到数据库
                    self::syncToDatabase($dir, $info);

                    // 合并 DB 状态
                    if (isset($dbPlugins[$info['identifier']])) {
                        // 使用文件中的基本信息（name, version等），但保留 DB 中的状态（is_active, installed_at）
                        $dbStatus = [
                            'id' => $dbPlugins[$info['identifier']]['id'],
                            'is_active' => $dbPlugins[$info['identifier']]['is_active'],
                            'installed_at' => $dbPlugins[$info['identifier']]['installed_at']
                        ];
                        $info = array_merge($info, $dbStatus);
                    } else {
                        // DB 中没有，默认为未激活
                        $info['is_active'] = 0;
                        $info['id'] = null; // 前端可能需要这个字段
                    }
                    
                    $plugins[] = $info;
                }
            }
        }

        // 3. 补充那些在 DB 中存在但在文件系统中消失的插件（Ghost Plugins）
        // 通常我们不想显示它们，或者显示为“已损坏”
        // 这里选择不显示，因为 Themes 也是只显示存在的
        
        // 按名称排序
        usort($plugins, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        
        return $plugins;
    }
    
    /**
     * 内部辅助：同步插件信息到数据库
     */
    private static function syncToDatabase($identifier, $pluginInfo) {
        $db = db();
        if (!$db) return false;
        
        $stmt_check = $db->prepare("SELECT id FROM plugins WHERE identifier = ?");
        $stmt_check->execute([$identifier]);
        $exists = $stmt_check->fetch();

        if ($exists) {
            $stmt = $db->prepare("UPDATE plugins SET name = ?, version = ?, author = ?, description = ? WHERE identifier = ?");
            return $stmt->execute([
                $pluginInfo['name'],
                $pluginInfo['version'],
                $pluginInfo['author'] ?? '',
                $pluginInfo['description'] ?? '',
                $identifier
            ]);
        } else {
            $stmt = $db->prepare(
                "INSERT INTO plugins (name, identifier, version, author, description, is_active, installed_at)
                 VALUES (?, ?, ?, ?, ?, 0, " . db_now() . ")"
            );
            return $stmt->execute([
                $pluginInfo['name'],
                $identifier,
                $pluginInfo['version'],
                $pluginInfo['author'] ?? '',
                $pluginInfo['description'] ?? ''
            ]);
        }
    }
    
    /**
     * 安装插件 (修改版，兼容SQLite)
     * @param string $pluginDir 插件目录
     * @return bool
     */
    public static function install($pluginDir) {
        // 保留此方法以兼容旧代码，但逻辑已过时
        // 实际上可以重定向到 installFromIdentifier 逻辑
        return true; 
    }
    
    /**
     * 根据标识符（目录名）安装或更新插件信息到数据库
     * @param string $identifier 插件标识符
     * @return bool
     */
    public static function installFromIdentifier($identifier) {
        $info = self::getPluginInfo($identifier);
        if ($info) {
            return self::syncToDatabase($identifier, $info);
        }
        return false;
    }
}

// 自动加载插件
function load_plugins() {
    $plugins = PluginManager::getInstalledPlugins();
    foreach ($plugins as $plugin) {
        $pluginFile = __DIR__ . '/../plugins/' . $plugin['identifier'] . '/plugin.php';
        if (file_exists($pluginFile)) {
            include_once $pluginFile;
        }
    }
}
