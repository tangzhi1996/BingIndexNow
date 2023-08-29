<?php
/**
 * Bing自动提交插件
 * 
 * @package BingIndexNow
 * @author FluffyOx
 * @version 1.0.0
 * @link https://github.com/FluffyOx/
 */
class BingIndexNow_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->write = array('BingIndexNow_Plugin', 'submitToBingIndex');

        // 创建数据库表
        self::createDatabaseTable();

        // 添加设置面板
        Typecho_Plugin::factory('Widget_Plugins_Config')->register = array('BingIndexNow_Plugin', 'config');
    }

    public static function deactivate() {}

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $apiKey = new Typecho_Widget_Helper_Form_Element_Text('apiKey', NULL, '', _t('Bing API 密钥'));
        $form->addInput($apiKey);

        $host = new Typecho_Widget_Helper_Form_Element_Text('host', NULL, '', _t('主机名'));
        $form->addInput($host);

        $keyLocation = new Typecho_Widget_Helper_Form_Element_Text('keyLocation', NULL, '', _t('密钥位置'));
        $form->addInput($keyLocation);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    public static function submitToBingIndex()
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $apiKey = $options->plugin('BingIndexNow')->apiKey;
        $host = $options->plugin('BingIndexNow')->host;
        $keyLocation = $options->plugin('BingIndexNow')->keyLocation;

        // 获取当前文章的信息
        $post = Typecho_Widget::widget('Widget_Contents_Post_Edit');
        $url = $post->permalink;
        $urlList = array($url);

        $requestData = array(
            'host' => $host,
            'key' => $apiKey,
            'keyLocation' => $keyLocation,
            'urlList' => $urlList
        );

        $jsonData = json_encode($requestData);

        $headers = array(
            'Content-Type: application/json; charset=utf-8',
            'Host: www.bing.com'
        );

        $ch = curl_init('https://www.bing.com/IndexNow');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);

        // 记录操作日志到数据库
        self::logToDatabase($post->cid, $response, Typecho_Widget::widget('Widget_User')->uid, $_SERVER['REMOTE_ADDR']);

        if (curl_errno($ch)) {
            echo 'Failed to submit to Bing Index';
        } else {
            echo 'Submitted to Bing Index';
        }

        curl_close($ch);
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
        echo 'Database Query Error: ' . $e->getMessage();
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
        echo 'Database Query Error: ' . $e->getMessage();
    }
}

}
