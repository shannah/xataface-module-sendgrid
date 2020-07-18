<?php
class modules_sendgrid {
    private $config = null;

	/**
	 * @brief The base URL to the datepicker module.  This will be correct whether it is in the 
	 * application modules directory or the xataface modules directory.
	 *
	 * @see getBaseURL()
	 */
	private $baseURL = null;
	/**
	 * @brief Returns the base URL to this module's directory.  Useful for including
	 * Javascripts and CSS.
	 *
	 */
	public function getBaseURL(){
		if ( !isset($this->baseURL) ){
			$this->baseURL = Dataface_ModuleTool::getInstance()->getModuleURL(__FILE__);
		}
		return $this->baseURL;
	}
	
	
	public function __construct(){
		
        
        $app = Dataface_Application::getInstance();
        $app->registerEventListener('mail', array(&$this, 'mail'));
        
    }
    
    function parseEmail($value, $multi=false) {
        if (is_string($value)) {
            if ($multi) {
                $out = array_map('trim', explode(',', $value));
                foreach ($out as $k=>$v) {
                    $out[$k] = $this->parseEmail($v);
                }
                return $out;
                
            }
            if (preg_match('/^(.*)<(.*)>$/', $value, $matches)) {
                $value = array(
                    'email' => trim($matches[2]),
                    'name' => trim($matches[1])
                );
            } else {
                $value = array(
                    'email' => trim($value)
                );
            }

        }
        return $value;
    }
    
    function extractHeaders($headers) {
        if (is_string($headers)) {
            $out = array();
            $lines = explode("\r\n", $headers);
            foreach ($lines as $line) {
                if (($pos = strpos($line, ':')) !== false) {
                    $key = trim(strtolower(substr($line, 0, $pos)));
                    $value = trim(substr($line, $pos+1));
                    if ($key == 'from') {
                        $value = $this->parseEmail($value);
                    }
                    $out[$key] = $value;
                }
            }
            return $out;
        }
        return $headers;
    }
    
    function mail($event) {
        if (!class_exists('\SendGrid\Mail\Mail')) {
            throw new Exception("The SendGrid module requires that SendGrid is installed in your application via composer.  See README for installation instructions");
        }
        $app = Dataface_Application::getInstance();
        $email = $this->parseEmail($event->email, true);
        $headers = $event->headers;
        $message = $event->message;
        $subject = $event->subject;
        if ($headers) {
            $headers = $this->extractHeaders($headers);
        } else {
            $headers = array();
        }
        if (@$event->from) {
            $headers['from'] = $this->parseEmail($event->from);
        }
        if (!@$headers['from']) {
            $headers['from'] = $_SERVER['SERVER_ADMIN'];
        }
        $parameters = $event->parameters;
        
        $m = new \SendGrid\Mail\Mail();
        if (@$headers['from']) $m->setFrom($headers['from']['email'], @$headers['from']['name']);
        if (@$subject) $m->setSubject($subject);
        foreach ($email as $e) {
            $m->addTo($e['email'], @$e['name']);
        }
        if (is_string($message)) {
            $m->addContent("text/plain", $message);
        } else {
            foreach ($message as $mime=>$content) {
                $m->addContent(
                    $mime, $content
                );
            }
        }
        
        $apiKey = null;
        if ($app->_conf['modules_sendgrid'] and @$app->_conf['modules_sendgrid']['API_KEY']) {
            $apiKey = $app->_conf['modules_sendgrid']['API_KEY'];
        }
        if (!$apiKey and getenv('SENDGRID_API_KEY')) {
            $apiKey = getenv('SENDGRID_API_KEY');
        }
        if (!$apiKey) {
            throw new Exception("The SendGrid module requires that you set the API_KEY in your conf.ini file.  See the README for installation instructions");
        }
        $logFile = null;
        if (@$app->_conf['modules_sendgrid']['log']) {
            $logFile = $app->_conf['modules_sendgrid']['log'];
        }
        $sendgrid = new \SendGrid($apiKey);
        try {
            if ($logFile) {
                file_put_contents($logFile, '['.date('Y-m-d H:i:s').'] Sending email {to:'.print_r($email, true).'; subject:'.print_r($subject, true).'; headers: '.print_r($headers, true).'}', FILE_APPEND | LOCK_EX);
            }
            $response = $sendgrid->send($m);
            if ($logFile) {
                file_put_contents($logFile, '['.date('Y-m-d H:i:s').'] Response: {statusCode: '.$response->statusCode().'; headers: '.print_r($response->headers(), true).'; body: '.print_r($response->body(), true).'}', FILE_APPEND | LOCK_EX);
            }
            $event->consumed = true;
            $event->out = ($response->statusCode() >= 200 and $response->statusCode() < 300);
            
        } catch (Exception $e) {
            if ($logFile) {
                file_put_contents($logFile, '['.date('Y-m-d H:i:s').'] Error: '.$e->getMessage(), FILE_APPEND | LOCK_EX);
            }
            $event->consumed = true;
            $event->out = false;
        }
        
        
        
        
    }
}
?>