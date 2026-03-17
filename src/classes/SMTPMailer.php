<?php

class SMTPMailer {

    /**
     *
     * @param string|string[] $to
     * @param string $subject
     * @param string $body
     * @param string $plainBody
     */
    public function __construct($to, $subject, $body, $plainBody = '') {
        $mail_setting = json_decode($GLOBALS['MAIL_CONFIG']);

        $this->client = new \PHPMailer(true);
        $this->client->isSMTP();
        $this->client->Host = $mail_setting->mail->host;
        $this->client->SMTPAuth = true;
        $this->client->SMTPSecure = 'ssl';
        $this->client->Username = $mail_setting->mail->username;
        $this->client->Password = $mail_setting->mail->password;
        $this->client->Port = $mail_setting->mail->port;
        $this->client->From = $mail_setting->mail->address;
        $this->client->FromName = $mail_setting->mail->name;
        $this->client->isHTML(true);
        $this->client->CharSet = 'UTF-8';

        $this->client->clearAllRecipients();
        $this->client->ClearAddresses();
        $this->client->ClearCCs();
        $this->client->ClearBCCs();

        if (is_array($to)) {
            foreach ($to as $addr) {
                $this->client->addAddress($addr);
            }
        } else {
            $this->client->addAddress($to);
        }

        $this->client->Subject = $subject;
        $this->client->Body = $body;
        $this->client->AltBody = $plainBody;
    }

    public function addAttachment($name, $bytes, $mimeType = null) {
        $this->client->addStringAttachment($bytes, $name);
    }

    public function send() {
        $errMsg = null;
        try {
            if ($this->client->send()) {
                // error_log("SUCCESSFUL");
            } else {
                $errMsg = 'SMTP ERROR';
            }
        } catch (\phpmailerException $e) {
            $errMsg = $e->getMessage();
        } catch (Exception $e) {
            $errMsg = $e->getMessage();
        }
        return $errMsg;
    }
}

