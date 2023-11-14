<?php

namespace Atshike\WeworkApi\Services;

/**
 * 企微回调.
 */
class WeWorkNotifyService
{
    /**
     * 回调解析 Encrypt.
     *
     * @param $notify_xml $request->getContent()
     * @param $param_data array request()->all()
     */
    public function getData(string $notify_xml, array $param_data, array $config = []): bool|array
    {
        $encodingAESKey = $config['wework_key'] ?? config('service.wework.wework_key'); // wework_key
        $receiveid = $config['wework_corpid'] ?? config('service.wework.wework_corpid'); // wework_corpid
        $token = $config['wework_token'] ?? config('service.wework.wework_token');  // wework_token

        $json_xml = json_encode(simplexml_load_string($notify_xml, 'SimpleXMLElement', LIBXML_NOCDATA));
        $post_data = json_decode($json_xml, true);
        if (empty($post_data)) {
            return false;
        }

        if (empty($post_data['Encrypt'])) {
            return $post_data;
        }

        $signature = $param_data['msg_signature'];
        $array = [
            $post_data['Encrypt'],
            $token,
            $param_data['timestamp'],
            $param_data['nonce'],
        ];
        $xml = $this->decrypt($array, $post_data['Encrypt'], $signature, $encodingAESKey, $receiveid);
        $simplexml_string = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);

        return (array) $simplexml_string;
    }

    /**
     * 验签/解密
     *
     * @param $array  array [timestamp|echostr|nonce|密文Encrypt/参数echostr]
     * @param $encrypt string 密文Encrypt/参数echostr
     * @param $signature string 参数 msg_signature
     * @param $encodingAESKey string wework key
     * @param $receiveid string 企业ID
     */
    public function decrypt(array $array, string $encrypt, string $signature, string $encodingAESKey, string $receiveid): bool|string
    {
        sort($array, SORT_STRING);
        $str = implode($array);
        $decryptSignature = sha1($str);
        if ($signature != $decryptSignature) {
            info("验签失败::{$signature}!={$decryptSignature}::".json_encode($array, JSON_UNESCAPED_UNICODE));

            return false;
        }

        $key = base64_decode($encodingAESKey.'=');
        $iv = substr($key, 0, 16);
        $decrypted = openssl_decrypt($encrypt, 'AES-256-CBC', $key, OPENSSL_ZERO_PADDING, $iv);
        $pad = ord(substr($decrypted, -1));
        $result = substr($decrypted, 0, (strlen($decrypted) - $pad));
        //拆分
        $content = substr($result, 16, strlen($result));
        $len_list = unpack('N', substr($content, 0, 4));
        $xml_len = $len_list[1];
        $xml_content = substr($content, 4, $xml_len);
        $from_receiveId = substr($content, $xml_len + 4);
        if ($from_receiveId != $receiveid) {
            info("企业ID错误::{$from_receiveId}!={$receiveid}");

            return false;
        }

        return $xml_content;
    }
}
