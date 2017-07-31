<?php if ( ! defined('MW_PATH')) exit('No direct script access allowed');

/**
 * BounceHandler
 *
 * This class is inspired from PHPMailer-BMH (wonderful class, very smart ideas there)
 * which has been created by Andy Prevost (andy.prevost@worxteam.com)
 */

class BounceHandler
{
    protected static $_rules = array();

    protected $_connection;

    protected $_errors = array();

    protected $_results;

    protected $_searchResults;

    public $connectionString;

    public $username;

    public $password;

    public $searchString;

    public $deleteMessages = false;

    public $deleteAllMessages = false;

    public $processLimit = 3000;

    public $processDaysBack = 3;

    public $processOnlyFeedbackReports = false;

    public $requiredHeaders = array('X-Mw-Campaign-Uid', 'X-Mw-Subscriber-Uid');

    public $openTimeout = 60;

    public $readTimeout = 3600;

    public $searchCharset = 'UTF-8';

    public $imapOpenParams = array();

    const BOUNCE_HARD = 'hard';

    const BOUNCE_SOFT = 'soft';
    
    const BOUNCE_INTERNAL = 'internal';

    const FEEDBACK_LOOP_REPORT = 'feedback-loop-report';

    const DIAGNOSTIC_CODE_RULES = "DIAGNOSTIC_CODE_RULES";

    const DSN_MESSAGE_RULES = "DSN_MESSAGE_RULES";

    const BODY_RULES = "BODY_RULES";

    const COMMON_RULES = "COMMON_RULES";

    public function __construct($connectionString = null, $username = null, $password = null, array $options = array())
    {
        $this->connectionString = $connectionString;
        $this->username = $username;
        $this->password = $password;

        foreach ($options as $name => $value) {
            if (property_exists($this, $name)) {
                $reflection = new ReflectionProperty($this, $name);
                if ($reflection->isPublic()) {
                    $this->$name = $value;
                }
            }
        }
        
    }

    public function getErrors()
    {
        return $this->_errors;
    }

    public function getResults()
    {
        if ($this->_results !== null) {
            return $this->_results;
        }

        $searchResults = $this->getSearchResults();
        if (empty($searchResults)) {
            $this->closeConnection();
            return $this->_results = array();
        }

        $results = array();
        $counter = 0 ;

        foreach ($searchResults as $messageId) {

            if ($this->processLimit > 0 && $counter >= $this->processLimit) {
                break;
            }

            $headers = @imap_fetchheader($this->_connection, $messageId);
            if (empty($headers)) {
                continue;
            }

            $result = array(
                'email'                     => null,
                'bounceType'                => null,
                'action'                    => null,
                'statusCode'                => null,
                'diagnosticCode'            => 'BOUNCED BACK',
                'headers'                   => null,
                'body'                      => null,
                'originalEmail'             => null,
                'originalEmailHeadersArray' => array(),
            );

            $found = false;
            // /Content-Type:((?:[^\n]|\n[\t ])+)(?:\n[^\t ]|$)/is
            if (preg_match ("/Content-Type:(.*)/is", $headers, $matches)) {
                if (preg_match("/multipart\/report/is", $matches[1]) && preg_match("/report-type=[\"']?feedback-report[\"']?/is", $matches[1])) {
                    $result['bounceType']     = self::FEEDBACK_LOOP_REPORT;
                    $result['diagnosticCode'] = 'FEEDBACK LOOP REPORT';
                    $headersArray = $this->getHeadersArray($headers);
                    $result['originalEmailHeadersArray'] = array_merge($result['originalEmailHeadersArray'], $headersArray);
                    if (isset($headersArray['Feedback-Type'])) {
                        $result['diagnosticCode'] .= ' - ' . ucfirst($headersArray['Feedback-Type']);
                    } elseif ($body = $this->extractBody($messageId)) {
                        $headersArray = $this->getHeadersArray($body);
                        $result['originalEmailHeadersArray'] = array_merge($result['originalEmailHeadersArray'], $headersArray);
                        if (isset($headersArray['Feedback-Type'])) {
                            $result['diagnosticCode'] .= ' - ' . ucfirst($headersArray['Feedback-Type']);
                        }
                    }
                    $found = true;
                }
            }

            // just to make sure we catch everything in the account!
            if ($this->processOnlyFeedbackReports && !$found) {
                $result['bounceType']     = self::FEEDBACK_LOOP_REPORT;
                $result['diagnosticCode'] = 'FEEDBACK LOOP REPORT';
                $found = true;
            }

            if (!$this->processOnlyFeedbackReports && !$found) {
                // /Content-Type:((?:[^\n]|\n[\t ])+)(?:\n[^\t ]|$)/is
                if (preg_match ("/Content-Type:(.*)/is", $headers, $matches)) {
                    if (preg_match("/multipart\/report/is", $matches[1]) && preg_match("/report-type=[\"']?delivery-status[\"']?/is", $matches[1])) {
                        $result = array_merge($result, $this->processDsn($messageId));
                    } else {
                        $result = array_merge($result, $this->processBody($messageId));
                    }
                } else {
                    $result = array_merge($result, $this->processBody($messageId));
                }
            }

            // this email headers
            $result['headers'] = $headers;

            // the body will also contain the original message(with headers and body!!!)
            $result['body'] = @imap_body($this->_connection, $messageId);

            // just the original message, headers and body!
            $result['originalEmail'] = @imap_fetchbody($this->_connection, $messageId, "3");

            // this is useful for reading back custom headers sent in the original email.
            $originalHeaders = $this->getHeadersArray($result['originalEmail']);
            $originalHeaders = array_merge($originalHeaders, $this->getHeadersArray($result['body']));
            $result['originalEmailHeadersArray'] = array_merge($result['originalEmailHeadersArray'], $originalHeaders);

            $valid = true;

            // only if we need to find specific required headers
            if (!empty($this->requiredHeaders)) {
                $originalEmailHeadersArrayKeys = array_map('strtoupper', array_keys($result['originalEmailHeadersArray']));
                $missingHeaders = array_map('strtoupper', $this->requiredHeaders);
                $notFound = array_diff($missingHeaders, $originalEmailHeadersArrayKeys);
                $valid = empty($notFound);
                unset($missingHeaders, $originalEmailHeadersArrayKeys, $notFound);
            }

            $markedForDelete = false;
            if ($valid) {
                if (!empty($result['bounceType'])) {
                    $results[] = $result;
                }
                if ($this->deleteMessages) {
                    @imap_delete($this->_connection, "$messageId:$messageId");
                    $markedForDelete = true;
                }
                ++$counter;
            }

            if (!$markedForDelete && $this->deleteAllMessages) {
                @imap_delete($this->_connection, "$messageId:$messageId");
            }
        }

        $this->closeConnection();

        return $this->_results = $results;
    }

    public function getHeadersArray($rawHeader)
    {
        static $cache = array();

        if (!is_string($rawHeader)) {
            return $rawHeader;
        }

        // because preg_match just bails out when attachments!
        $rawHeader = substr($rawHeader, 0, 50000);

        $key = sha1($rawHeader);
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $headers     = array();
        $headerLines = array();
        $regexes     = array(
            '/([^: ]+): (.+?(?:\r\n\s(?:.+?))*)\r\n/m',
            '/([a-z\-\_]+): ([^\r\n]+)/sim'
        );

        foreach ($regexes as $regex) {
            $matched = preg_match_all($regex, $rawHeader, $headerLines);
            if ($matched && !empty($headerLines)) {
                break;
            }
        }

        if (!empty($headerLines[0])) {
            foreach ($headerLines[0] as $line) {
                if (strpos($line, ':') === false) {
                    continue;
                }
                $lineParts = explode(':', $line, 2);
                if (count($lineParts) != 2) {
                    continue;
                }
                list($name, $value) = $lineParts;
                $headers[$name] = trim($value);
            }
        }

        return $cache[$key] = $headers;
    }

    protected function processDsn($messageId)
    {
        $result = array();

        $action = $statusCode = $diagnosticCode = null;

        // first part of DSN (Delivery Status Notification), human-readable explanation
        $dsnMessage = @imap_fetchbody($this->_connection, $messageId, "1");
        $dsnMessageStructure = @imap_bodystruct($this->_connection, $messageId, "1");

        if (!empty($dsnMessageStructure)) {
            if ($dsnMessageStructure->encoding == 4) {
                $dsnMessage = quoted_printable_decode($dsnMessage);
            } elseif ($dsnMessageStructure->encoding == 3) {
                $dsnMessage = base64_decode($dsnMessage);
            }
        }

        // second part of DSN (Delivery Status Notification), delivery-status
        $dsnReport = @imap_fetchbody($this->_connection, $messageId, "2");

        if (preg_match("/Original-Recipient: rfc822;(.*)/i", $dsnReport, $matches)) {
            $emailArr = imap_rfc822_parse_adrlist($matches[1], 'default.domain.name');
            if (!empty($emailArr) && isset($emailArr[0]->host) && $emailArr[0]->host != '.SYNTAX-ERROR.' && $emailArr[0]->host != 'default.domain.name' ) {
                $result['email'] = $emailArr[0]->mailbox.'@'.$emailArr[0]->host;
            }
        } else if (preg_match("/Final-Recipient: rfc822;(.*)/i", $dsnReport, $matches)) {
            $emailArr = imap_rfc822_parse_adrlist($matches[1], 'default.domain.name');
            if (!empty($emailArr) && isset($emailArr[0]->host) && $emailArr[0]->host != '.SYNTAX-ERROR.' && $emailArr[0]->host != 'default.domain.name' ) {
                $result['email'] = $emailArr[0]->mailbox.'@'.$emailArr[0]->host;
            }
        }

        if (preg_match ("/Action: (.+)/i", $dsnReport, $matches)) {
            $action = strtolower(trim($matches[1]));
        }

        if (preg_match ("/Status: ([0-9\.]+)/i", $dsnReport, $matches)) {
            $statusCode = $matches[1];
        }

        // Could be multi-line , if the new line is beginning with SPACE or HTAB
        if (preg_match ("/Diagnostic-Code:((?:[^\n]|\n[\t ])+)(?:\n[^\t ]|$)/is", $dsnReport, $matches)) {
            $diagnosticCode = $matches[1];
        }

        if (empty($result['email'])) {
            if (preg_match ("/quota exceed.*<(\S+@\S+\w)>/is", $dsnMessage, $matches)) {
                $result['email'] = $matches[1];
                $result['bounceType'] = self::BOUNCE_SOFT;
            }
        } else {
            $rules = $this->getRules();
            $foundMatch = false;
            foreach ($rules[self::DIAGNOSTIC_CODE_RULES] as $rule) {
                if (!is_array($rule['regex'])) {
                    $rule['regex'] = array($rule['regex']);
                }
                foreach ($rule['regex'] as $regex) {
                    if (preg_match($regex, $diagnosticCode, $matches)) {
                        $foundMatch = true;
                        $result['bounceType'] = $rule['bounceType'];
                        break;
                    }
                }
                if ($foundMatch) {
                    break;
                }
            }
            if (!$foundMatch) {
                foreach ($rules[self::DSN_MESSAGE_RULES] as $rule) {
                    if (!is_array($rule['regex'])) {
                        $rule['regex'] = array($rule['regex']);
                    }
                    foreach ($rule['regex'] as $regex) {
                        if (preg_match($regex, $dsnMessage, $matches)) {
                            $foundMatch = true;
                            $result['bounceType'] = $rule['bounceType'];
                            break;
                        }
                    }
                    if ($foundMatch) {
                        break;
                    }
                }
            }
        }

        $result['action'] = $action;
        $result['statusCode'] = $statusCode;
        $result['diagnosticCode'] = $diagnosticCode;

        return $result;
    }

    protected function processBody($messageId)
    {
        $result = array();

        if (!($body = $this->extractBody($messageId))) {
            return $result;
        }

        $rules = $this->getRules();
        $foundMatch = false;
        foreach ($rules[self::BODY_RULES] as $rule) {
            if (!is_array($rule['regex'])) {
                $rule['regex'] = array($rule['regex']);
            }
            foreach ($rule['regex'] as $regex) {
                if (preg_match($regex, $body, $matches)) {
                    $foundMatch = true;
                    $result['bounceType'] = $rule['bounceType'];
                    if (isset($rule['regexEmailIndex']) && isset($matches[$rule['regexEmailIndex']])) {
                        $result['email'] = $matches[$rule['regexEmailIndex']];
                    }
                    break;
                }
            }
            if ($foundMatch) {
                break;
            }
        }

        return $result;
    }

    protected function extractBody($messageId)
    {
        static $extracted = array();
        if (isset($extracted[$messageId])) {
            return $extracted[$messageId];
        }

        $body = '';
        $structure = @imap_fetchstructure($this->_connection, $messageId);
        
        if (!empty($structure)) {
            if (in_array($structure->type, array(0, 1))) {
                $body = @imap_fetchbody($this->_connection, $messageId, "1");
                // Detect encoding and decode - only base64
                if (isset($structure->parts) && isset($structure->parts[0]) && $structure->parts[0]->encoding == 4) {
                    $body = quoted_printable_decode($body);
                } elseif (isset($structure->parts) && $structure->parts[0] && $structure->parts[0]->encoding == 3) {
                    $body = base64_decode($body);
                }
            } elseif ($structure->type == 2) {
                $body = @imap_body($this->_connection, $messageId);
                if ($structure->encoding == 4) {
                    $body = quoted_printable_decode($body);
                } elseif ($structure->encoding == 3) {
                    $body = base64_decode($body);
                }
                $body = substr($body, 0, 1000);
            }
        }

        return $extracted[$messageId] = $body;
    }

    protected function getSearchResults()
    {
        if ($this->_searchResults !== null) {
            return $this->_searchResults;
        }

        if (!$this->openConnection()) {
            return $this->_searchResults = array();
        }

        if (empty($this->searchString)) {
            $this->searchString = sprintf('UNDELETED SINCE "%s"', date('d-M-Y', strtotime(sprintf('-%d days', (int)$this->processDaysBack))));
        }

        $searchResults = @imap_search($this->_connection, $this->searchString, null, $this->searchCharset);
        $errors        = imap_errors();
        if (empty($searchResults) || !is_array($searchResults)) {
            $searchResults = array();
         }

         return $this->_searchResults = $searchResults;
    }

    protected function openConnection()
    {
        if ($this->_connection !== null) {
            return $this->_connection;
        }

        if (!function_exists('imap_open')) {
            $this->_errors[] = 'The IMAP extension is not enabled on this server!';
            return false;
        }

        if (empty($this->connectionString) || empty($this->username) || empty($this->password)) {
            $this->_errors[] = 'The connection string, username and password are required in order to open the connection!';
            return false;
        }

        imap_timeout(IMAP_OPENTIMEOUT, (int)$this->openTimeout);
        imap_timeout(IMAP_READTIMEOUT, (int)$this->readTimeout);

        $connection = @imap_open($this->connectionString, $this->username, $this->password, null, 1, $this->imapOpenParams);
        $errors     = imap_errors();
        $error      = null;

        if (!empty($errors) && is_array($errors)) {
            $error = implode("<br />", array_unique(array_values((array)$errors)));
            if (stripos($error, 'insecure server advertised') !== false) {
                $error = null;
            }
            if ($error) {
                $this->_errors[] = $error;
                return false;
            }
        }

        if (empty($connection)) {
            $this->_errors[] = 'Unknown error while opening the connection!';
            return false;
        }

        $this->_connection = $connection;
        return true;
    }

    protected function closeConnection()
    {
        if ($this->_connection !== null) {
            if ($this->deleteMessages || $this->deleteAllMessages) {
                @imap_expunge($this->_connection);
            }
            @imap_close($this->_connection);
        }
    }

    protected function getRules()
    {
        if (!empty(self::$_rules)) {
            return self::$_rules;
        }

        // 1.3.9.7
        self::$_rules = BounceHandlerHelper::getRules();
        
        if (empty(self::$_rules)) {
            self::$_rules = require(dirname(__FILE__) . '/rules.php');
        }

        if (is_file($customRulesFile = dirname(__FILE__) . '/rules-custom.php')) {
            self::$_rules = CMap::mergeArray(self::$_rules, require $customRulesFile);
        }

        self::$_rules[self::DIAGNOSTIC_CODE_RULES] = CMap::mergeArray(self::$_rules[self::DIAGNOSTIC_CODE_RULES], self::$_rules[self::COMMON_RULES]);
        self::$_rules[self::DSN_MESSAGE_RULES]     = CMap::mergeArray(self::$_rules[self::DSN_MESSAGE_RULES], self::$_rules[self::COMMON_RULES]);
        self::$_rules[self::BODY_RULES]            = CMap::mergeArray(self::$_rules[self::BODY_RULES], self::$_rules[self::COMMON_RULES]);
        self::$_rules[self::COMMON_RULES]          = array();
        
        // since 1.3.6.3
        if (is_file($customRulesFile = dirname(__FILE__) . '/rules-custom-override.php')) {
            $_rules = require $customRulesFile;
            self::$_rules = array();
            self::$_rules[self::DIAGNOSTIC_CODE_RULES] = $_rules[self::COMMON_RULES];
            self::$_rules[self::DSN_MESSAGE_RULES]     = $_rules[self::COMMON_RULES];
            self::$_rules[self::BODY_RULES]            = $_rules[self::COMMON_RULES];
            self::$_rules[self::COMMON_RULES]          = array();
        }
        
        return self::$_rules;
    }
}
