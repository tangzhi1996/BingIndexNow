<?php
/**
 * Bing自动提交插件
 * 
 * @package BingIndexNow
 * @author FluffyOx,瓜瓜
 * @version 1.0.1
 * @link https://github.com/fluffyox/Typecho_BingIndexNow
 */
class BingIndexNow_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('BingIndexNow_Plugin', 'submitToBingIndex');
        // 创建数据库表
        self::createDatabaseTable();
        // 添加设置面板
        Typecho_Plugin::factory('Widget_Plugins_Config')->register = array('BingIndexNow_Plugin', 'config');
        return _t('欢迎使用！！第一次使用请查看<a href="https://github.com/fluffyox/Typecho_BingIndexNow">使用方法</a>');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate() {
        // 创建数据库表
        self::deleteDatabaseTable();
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $apiKey = new Typecho_Widget_Helper_Form_Element_Text('apiKey', NULL, '', _t('Bing API 密钥'), '申请地址：https://www.bing.com/webmasters/indexnow');
        $form->addInput($apiKey);

        $host = new Typecho_Widget_Helper_Form_Element_Text('host', NULL, '', _t('主机名'), '例如: www.example.org');
        $form->addInput($host);

        $keyLocation = new Typecho_Widget_Helper_Form_Element_Text('keyLocation', NULL, '', _t('密钥位置'), '例如：https://www.example.org/0dcee520a4294f8eb5134f697c131a42.txt');
        $form->addInput($keyLocation);

    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    public static function submitToBingIndex($contents,$classa) 
    {


        //如果文章属性为隐藏则返回不执行
		if( 'publish' != $contents['visibility']){
            return;
        }
        // 获取配置
        $options = Typecho_Widget::widget('Widget_Options');
        $apiKey = $options->plugin('BingIndexNow')->apiKey;
        $host = $options->plugin('BingIndexNow')->host;
        $keyLocation = $options->plugin('BingIndexNow')->keyLocation;
    
        // 验证选项是否存在
        if (empty($apiKey) || empty($host) || empty($keyLocation)) {
            return _t('Bing IndexNow 插件选项配置不正确。');
        }

        // 地址拼接
        $parsedUrl = parse_url($keyLocation);
        $website = sprintf("%s://%s", $parsedUrl['scheme'], $parsedUrl['host']).'/index.php/archives/';

    
        // 获取当前文章的信息
        $post = Typecho_Widget::widget('Widget_Contents_Post_Edit');
        if (!$post->have()) {
            return _t('获取文章信息以提交到 Bing IndexNow 失败。');
        }

        $cid = $post->cid;
        $url = $website.$cid;
        $urlList = array($url);
    
        $requestData = array(
            'host' => $host,
            'key' => $apiKey,
            'keyLocation' => $keyLocation,
            'urlList' => $urlList
        );
    
        $jsonData = json_encode($requestData);
        // 检查 JSON 编码是否失败
        if ($jsonData === false) {
            return _t('将请求数据编码为 JSON 以提交到 Bing IndexNow 失败。');
        }
    
        $headers = array(
            'Content-Type: application/json; charset=utf-8'
        );
    
        // 定义请求的 URL
        $ch = curl_init('https://api.indexnow.org/IndexNow');
        // 检查 cURL 初始化是否失败
        if ($ch === false) {
            return _t('初始化 cURL失败。');
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // 执行请求
        $response = curl_exec($ch);
        // 获取响应
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $msg = '';
        if (curl_errno($ch)) {
            $msg = '提交到 Bing Index 失败: '. curl_error($ch);
        } elseif ($httpCode !== 200) {
            $msg = '提交到 Bing Index 失败。HTTP 状态码: '. $httpCode;
        } else {
            $msg ='已成功提交到 Bing Index';
        }
    
        curl_close($ch);
        // 记录操作日志到数据库
        // self::logToDatabase($post->cid, $response, Typecho_Widget::widget('Widget_User')->uid, $_SERVER['REMOTE_ADDR']);
        self::logToDatabase($post->cid, "请求数据".$jsonData."响应:".$response.";msg".$msg , Typecho_Widget::widget('Widget_User')->uid, $_SERVER['REMOTE_ADDR']);
    
    }


// 创建数据库表
private static function createDatabaseTable()
{
    try {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS {$prefix}bing_index_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT,
    user_id INT,
    user_ip VARCHAR(255),
    response TEXT,
    timestamp INT
)
SQL;

        $db->query($sql);
    } catch (Exception $e) {
        echo '数据表操作错误: ' . $e->getMessage();
    }
}

// 删除数据库表
private static function deleteDatabaseTable()
{
    try {
        $db = Typecho_Db::get();
        $db->query("DROP TABLE `{$db->getPrefix()}bing_index_log`", Typecho_Db::WRITE);
    } catch (Exception $e) {
        echo '数据表操作错误: ' . $e->getMessage();
    }
}

// 将操作日志写入数据库
private static function logToDatabase($postId, $response, $userId, $userIp)
{
    try {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();

        $log = array(
            'post_id' => $postId,
            'user_id' => $userId,
            'user_ip' => $userIp,
            'response' => $response,
            'timestamp' => time()
        );

        $db->query($db->insert($prefix . 'bing_index_log')->rows($log));
    } catch (Exception $e) {
        echo '数据表操作错误: ' . $e->getMessage();
    }
}

}
