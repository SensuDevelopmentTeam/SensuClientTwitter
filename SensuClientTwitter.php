<?php
/*!
 * @file SensuClientTwitter.php
 * @author Sensu Development Team
 * @date 2018/04/20
 * @brief Twitter用Sensuクライアント
 */

require_once __DIR__.'/Config.php';
require_once __DIR__.'/SensuClient.php';
require_once __DIR__.'/RandomString.php';
require __DIR__.'/vendor/autoload.php';

class SensuClientTwitter
{
    /*!
     * @brief SensuプラットフォームAPIクライアント
     */
    private $sensu;

    /*!
     * @brief Twitter APIクライアント
     */
    private $twitter;

    /*!
     * @brief コンストラクタ
     */
    public function __construct()
    {
        $this->sensu = new \SensuDevelopmentTeam\SensuClient(Config::SENSU_PLATFORM_API_KEY);
        $this->twitter =  new \mpyw\Cowitter\Client([
            Config::TWITTER_API_CONSUMER_KEY,
            Config::TWITTER_API_CONSUMER_SECRET,
            Config::TWITTER_API_ACCESS_TOKEN,
            Config::TWITTER_API_ACCESS_TOKEN_SECRET
        ]);
    }

    /*!
     * @brief クライアントを実行
     */
    public function run()
    {
        // 自分の情報を取得
        $account_info = $this->twitter->get('account/verify_credentials');

        // メンションタイムラインをストリーミングAPIより取得
        $self = $this;
        \mpyw\Co\Co::wait($this->twitter->streamingAsync('user', function ($stream) use ($self, $account_info) {
            if (!isset($stream->direct_message)) // メンション
            {
                // 送信者が設定されていなければ中止
                if (!isset($stream->user))
                {
                    return;
                }

                $sender = $stream->user->id_str;
                // 送信者が自分であれば中止
                if ($sender === $account_info->id_str)
                {
                    return;
                }

                // 先頭にメンションがなければ中止
                preg_match('/^((\s+|^)@\S+)*\s/', $stream->text, $match);
                if (count($match) < 1)
                {
                    return;
                }
                if (strpos($match[0], '@'.$account_info->screen_name) === false)
                {
                    return;
                }

                // 先頭のメンションを排除
                $command = preg_replace('/^((\s+|^)@\S+)*\s/', '', $stream->text);
                // 命令を分解
                $command = $self::getCommandFromText($command);
            }
            else // ダイレクトメッセージ
            {
                $sender = $stream->direct_message->sender->id_str;
                // 送信者が自分であれば中止
                if ($sender === $account_info->id_str)
                {
                    return;
                }

                // 命令を分解
                $command = $self::getCommandFromText($stream->direct_message->text);
            }

            // 投げ銭コマンド
            if (isset($command[0]) && strcasecmp($command[0], 'tip') == 0)
            {
                if (isset($command[3]))
                {
                    try
                    {
                        $command[3] = $self->getUserIdFromScreenName(ltrim($command[3], '@'));
                    }
                    catch (Exception $e)
                    {
                        $command[3] = '';
                    }
                }
            }

            // 命令を送信
            $result = $self->sensu->command($sender, $command);

            if (!isset($stream->direct_message)) // メンション
            {
                if ($result->status === 'COMMAND_NOT_FOUND')
                {
                    return;
                }

                // 表示用メッセージが設定されていなければ内部エラー
                if (!isset($result->message))
                {
                    $self->twitter->post('statuses/update', [
                        'status' => '@'.$stream->user->screen_name."\n内部エラーが発生しました。\nAn internal error occurred.\n\n".RandomString::generate(4),
                        'in_reply_to_status_id' => $stream->id
                    ]);
                    return;
                }

                // プッシュメッセージ
                if (isset($result->push_message))
                {
                    // 投げ銭コマンド
                    if (isset($command[0]) && strcasecmp($command[0], 'tip') == 0)
                    {
                        $self->twitter->post('statuses/update', [
                            'status' => '@'.$self->getScreenNameFromUserId($command[3])."\n".sprintf($result->push_message, '@'.$stream->user->screen_name)."\n\n".RandomString::generate(4)
                        ]);
                    }
                }

                // 返信
                $self->twitter->post('statuses/update', [
                    'status' => '@'.$stream->user->screen_name."\n\n".$result->message."\n\n".RandomString::generate(4),
                    'in_reply_to_status_id' => $stream->id
                ]);
            }
            else // ダイレクトメッセージ
            {
                // 表示用メッセージが設定されていなければ内部エラー
                if (!isset($result->message))
                {
                    $self->twitter->post('direct_messages/new', [
                        'user_id' => intval($sender),
                        'text' => "内部エラーが発生しました。\nAn internal error occurred.\n\n".RandomString::generate(4)
                    ]);
                    return;
                }

                // プッシュメッセージ
                if (isset($result->push_message))
                {
                    // 投げ銭コマンド
                    if (isset($command[0]) && strcasecmp($command[0], 'tip') == 0)
                    {
                        $self->twitter->post('statuses/update', [
                            'status' => '@'.$self->getScreenNameFromUserId($command[3])."\n".sprintf($result->push_message, '@'.$stream->direct_message->sender->screen_name)."\n\n".RandomString::generate(4)
                        ]);
                    }
                }

                // 返信
                $self->twitter->post('direct_messages/new', [
                    'user_id' => intval($sender),
                    'text' => $result->message."\n\n".RandomString::generate(4)
                ]);
            }
        }));
    }

    /*
     * @brief 表示名からユーザーIDを取得
     * @param $screen_name 表示名
     * @return ユーザーID
     */
    public function getUserIdFromScreenName($screen_name)
    {
        $user_info = $this->twitter->get('users/show', [
            'screen_name' => $screen_name
        ]);
        return $user_info->id_str;
    }
    
    /*
     * @brief ユーザーIDから表示名を取得
     * @param $id ユーザーID
     * @return 表示名
     */
    public function getScreenNameFromUserId($id)
    {
        $user_info = $this->twitter->get('users/show', [
            'user_id' => $id
        ]);
        return $user_info->screen_name;
    }

    /*!
     * @brief 発言本文より命令を取得
     * @param $test 発言本文
     * @return 命令
     */
    private static function getCommandFromText($text)
    {
        $command = htmlspecialchars_decode($text, ENT_NOQUOTES);
        $result = preg_split('/[ \n](?=(?:[^\\"]*\\"[^\\"]*\\")*(?![^\\"]*\\"))/', $command, -1, PREG_SPLIT_NO_EMPTY);
        $result = str_replace('"', '', $result);
        return $result;
    }
}

$client = new SensuClientTwitter();
$client->run();
