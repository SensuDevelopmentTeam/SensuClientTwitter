<?php
/*!
 * @file RandomString.php
 * @author Sensu Development Team
 * @date 2018/02/22
 * @brief 無作為な文字列
 */

class RandomString
{
    /*!
     * @brief 無作為な文字列を生成
     * @param $length 文字列長
     * @param $characters 使用する文字
     * @return 無作為な文字列
     */
    public static function generate($length, $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ')
    {
        $result = '';
        for ($i = 0; $i < $length; $i++)
        {
            $result .= $characters[mt_rand(0, strlen($characters) - 1)];
        }
        return $result;
    }
}
