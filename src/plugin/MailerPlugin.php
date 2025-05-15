<?php

namespace Xakki\PhpErrorCatcher\plugin;

use Exception;
use PHPMailer\PHPMailer\PHPMailer;

class MailerPlugin extends BasePlugin
{

    /**
     * Включает отправку писем (если это PHPMailer)
     * @var null|callback|PHPMailer
     */
    protected $mailer = null;

    /**
     * Шаблон темы
     * @var string
     */
    protected $mailerSubjectPrefix = '{SITE} - DEV ERROR {DATE}';

    /**
     * Получаем PHPMailer
     * @return PHPMailer|null
     */
    protected function getMailer()
    {
        if ($this->mailer && is_callable($this->mailer)) {
            $this->mailer = call_user_func_array($this->mailer, []);
        }
        return (is_object($this->mailer) ? $this->mailer : null);
    }

    /**
     * Отправка письма об ошибках
     * @param $message
     * @return bool
     */
    private function sendErrorMail($message)
    {
        try {
            $cmsmailer = $this->getMailer();
            if (!$cmsmailer) return false;

            $cmsmailer->Body = $message;
            $cmsmailer->Subject = str_replace([
                '{SITE}',
                '{DATE}'
            ], [
                (empty($_SERVER['HTTP_HOST']) ? '' : $_SERVER['HTTP_HOST']),
                date(' Y-m-d H:i:s')
            ], $this->mailerSubjectPrefix);
            return $cmsmailer->Send();
        } catch (Exception $e) {
            $this->handleException($e);
        }
        return false;
    }

    public function shutdown()
    {

        if ($this->_hasError || ($this->_allLogs && !$this->logOnlyIfError)) { // ($this->_allLogs || $this->_overMemory)
            $fileLog = $this->renderLogs();
            $mailStatus = $errorMailLog = false;
            try {
                ob_start();
                $mailStatus = $this->sendErrorMail($fileLog);
                $errorMailLog = ob_get_contents();
                ob_end_clean();
            } catch (Exception $e) {
                $this->handleException($e);
            }
            if ($mailStatus) {
                $fileLog .= '<div class="bg-success">Выслано письмо на почту</div>';
            } elseif ($errorMailLog) {
                $fileLog .= '<div><pre class="bg-danger">Ошибка отправки письма' . PHP_EOL . static::_e($errorMailLog) . '</pre></div>';
            }

        }
    }
}