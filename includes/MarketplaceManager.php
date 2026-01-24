<?php 
/** 
 * Marketplace Manager 
 * 全自动应用市场管理器 - 支持自动更新与故障自愈 
 */ 
class MarketplaceManager { 
    // 💡 提示：请去 GitHub 确认 Marketplace 仓库的分支名是 master 还是 main 
    private static $baseUrl = 'https://raw.githubusercontent.com/Yuan-ICP/Marketplace/master/'; 
    private static $cacheDir = YICP_ROOT . '/data/'; 

    public static function getPlugins() { 
        return self::fetchJson('plugins.json', 'market_plugins_cache.json'); 
    } 

    public static function getThemes() { 
        return self::fetchJson('themes.json', 'market_themes_cache.json'); 
    } 

    private static function fetchJson($filename, $cacheFilename) { 
        $url = self::$baseUrl . $filename; 
        $cacheFile = self::$cacheDir . $cacheFilename; 

        // 1. 确保目录存在 
        if (!is_dir(self::$cacheDir)) { 
            mkdir(self::$cacheDir, 0755, true); 
        } 

        $cacheExists = file_exists($cacheFile); 
        $cacheData = $cacheExists ? json_decode(file_get_contents($cacheFile), true) : []; 
        
        // 2. 自动判定是否需要重新抓取 
        $needsFetch = false; 
        if (!$cacheExists) { 
            $needsFetch = true; // 文件不存在，抓取 
        } elseif (empty($cacheData) || !is_array($cacheData)) { 
            $needsFetch = true; // 文件虽然存在但内容是空的(之前失败了)，强制重新抓取 
        } elseif (time() - filemtime($cacheFile) > 3600) { 
            $needsFetch = true; // 缓存超过1小时，抓取更新 
        } 

        if ($needsFetch) { 
            // 3. 构建强大的请求上下文 (解决 GitHub 拒绝连接的问题) 
            $opts = [ 
                'http' => [ 
                    'method' => 'GET', 
                    'header' => [ 
                        'User-Agent: Yuan-ICP-App-Market/1.1', // 必须：GitHub 禁止无 UA 的请求 
                        'Accept: application/json', 
                        'Connection: close' 
                    ], 
                    'timeout' => 8, // 避免 GitHub 抽风导致后台卡死 
                    'ignore_errors' => true 
                ], 
                'ssl' => [ 
                    'verify_peer' => false, // 提高兼容性：忽略本地服务器证书配置问题 
                    'verify_peer_name' => false, 
                ] 
            ]; 

            $context = stream_context_create($opts); 
            $newJson = @file_get_contents($url, false, $context); 
            
            // 4. 验证新获取的数据 
            if ($newJson) { 
                $newData = json_decode($newJson, true); 
                if (!empty($newData) && is_array($newData)) { 
                    // 抓取成功且有内容，覆盖本地缓存 
                    file_put_contents($cacheFile, $newJson); 
                    return $newData; 
                } 
            } 
            
            // 5. 抓取失败时的处理 
            if ($cacheExists) { 
                // 如果抓取失败但本地有旧的（即便是一小时前的），先吐出旧的 
                return $cacheData; 
            } 
        } 

        return $cacheData; 
    } 
}
