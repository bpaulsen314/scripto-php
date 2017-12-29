<?php
namespace Bpaulsen314\Scripto;

use \ErrorException;
use \Exception;
use Bpaulsen314\Perfecto\Object;

class Script extends Object
{
    const EXIT_RUN_SKIPPED = -2;
    const EXIT_HELP = -1;
    const EXIT_SUCCESS = 0;
    const EXIT_RUN_FAILURE = 1;
    const EXIT_START_FAILURE = 2;

    const PID_DIR = "/tmp/script-pid/";

    protected $_config = [];

    protected $_arguments = [];
    protected $_debug = 0;
    protected $_endTime;
    protected $_executedFile;
    protected $_exitCode;
    protected $_pidFile;
    protected $_startTime;

    public function __construct($config = null)
    {
        if (is_array($config)) {
            $this->_config = $config;
        }
        $config_defaults = $this->_getConfigDefaults();
        $this->_config = array_replace_recursive(
            $config_defaults, $this->_config
        );
        ksort($this->_config["arguments"]);
    }

    public final function shutdown()
    {
        $failure = error_get_last();
        if ($failure["type"] !== E_ERROR) {
            $failure = null;
        }

        if ($failure) {
            $this->_exitCode = static::EXIT_RUN_FAILURE;
            $this->log("UNEXPECTED ERROR ENCOUNTERED!!!");
            if (preg_match("#stack trace:#i", $failure["message"])) {
                $this->log($failure["message"]);
            } else {
                $this->log($failure["message"] . " in ", false);
                $this->log($failure["file"] . " on line ", false);
                $this->log($failure["line"]);
            }
        }

        $this->_endTime = time();

        switch ($this->_exitCode) {
            case static::EXIT_HELP:
                $this->_exitCode = static::EXIT_SUCCESS;
                break;
            
            case static::EXIT_SUCCESS:
                $this->_reportSuccess();
                $this->_logFooter();
                break;

            case static::EXIT_RUN_FAILURE:
                $this->_reportFailure($failure);
                $this->_logFooter();
                break;

            case static::EXIT_RUN_SKIPPED:
                $this->_exitCode = static::EXIT_SUCCESS;
                break;
        }

        $this->_updatePidFile(true);

        exit($this->_exitCode);
    }

    public final function start($arguments)
    {
        ini_set("display_errors", 0);
        set_error_handler(
            function($errno, $errstr, $errfile, $errline) {
                if (error_reporting() & $errno) {
                    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
                }
            }
        );
        $tz = ini_get("date.timezone");
        if (!$tz) {
            ini_set("date.timezone", "UTC");
        }
        register_shutdown_function([$this, "shutdown"]);

        // inialize default arguments
        foreach ($this->_config["arguments"] as $argument => $arg_def) {
            if (isset($arg_def["default_value"])) {
                $this->_setArgument($argument, $arg_def["default_value"]);
            }
        }

        // set client specified arguments
        if (!is_null($arguments)) {
            if ($arguments) {
                if (is_file(getcwd() . "/" . $arguments[0])) {
                    $this->_executedFile = realpath(getcwd() . "/" . $arguments[0]);
                } else if (is_file($arguments[0])) {
                    $this->_executedFile = realpath($arguments[0]);
                }
                if ($this->_executedFile) {
                    $this->_config["name"] = basename($this->_executedFile);
                    $arguments = $this->_parseCliArguments($arguments);
                }
            }
            foreach ($arguments as $argument => $value) {
                $this->_setArgument($argument, $value);
            }
        }

        // // validate arguments
        $argument_errors = [];
        foreach ($this->_arguments as $argument => $value) {
            $errors = $this->_validateArgument($argument, $value);
            if (is_array($errors)) {
                $argument_errors = array_merge($argument_errors, $errors);
            }
        }

        // set debug level, if it's specified
        if (
            isset($this->_arguments["debug"]) && 
            preg_match("#^[1-9][0-9]*$#", $this->_arguments["debug"])
        ) {
            $this->_debug = (int) $this->_arguments["debug"];
        } 

        // only "run" script if help parameter is not provided and there are
        // no issues with the arguments provided
        if (array_key_exists("help", $this->_arguments)) {
            $this->_exitCode = static::EXIT_HELP;
            $text = $this->_getHelpText();
            echo $text;
        } else if ($argument_errors) {
            $this->_exitCode = static::EXIT_START_FAILURE;
            echo "\n";
            echo "ARGUMENT ERROR(S) ENCOUNTERED!!!\n";
            echo "\n";
            foreach ($argument_errors as $error) {
                echo "    {$error}\n";
            }
            echo "\n";
            echo "For more information consider trying \"--help\".\n";
            echo "\n";
            $this->_failure = false;
        } else {
            $this->_exitCode = static::EXIT_SUCCESS;

            if ($this->_updatePidFile()) {
                $this->_logHeader();
                $this->_startTime = time();

                if (isset($this->_config["callback"])) {
                    call_user_func($this->_config["callback"], $this);
                } else {
                    $this->_run();
                }
            } else {
                $this->_exitCode = static::EXIT_RUN_SKIPPED;
            }
        }
    }

    public function email($content, $subject = null, $to = null, $options = [])
    {
        $result = false;

        // subject manipulation
        if (!$subject) {
            $subject = "SCRIPT ALERT";
        }
        $host = gethostname();
        $hash = substr(sha1($content), 0, 8);
        $subject .= " <{$hash}>";
        // $execution_env = "{$host}: {$this->_executedFile}";
        // $subject .= " [{$execution_env}] <{$hash}>";

        if (!$to && isset($this->_arguments["email"])) {
            $to = $this->_arguments["email"];
        }

        if (is_array($to)) {
            $to = implode(",", $to);
        }

        if ($to) {
            $headers  = "From: noreply@{$host}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $result = mail($to, $subject, $content, $headers);
        } else {
            throw new Exception("An attempt was made to send an email without a valid recipient.");
        }

        return $result;
    }

    public function log($text = "", $append_eol = true, $timestamp = true)
    {
        static $sol = true;

        // hack for reseting start of line
        if ($text === "<#SOL#>") {
            $sol = true;
            return;
        }

        if (is_array($text)) {
            $text = var_export($text, true);
            $this->log($text, $append_eol, $timestamp);
        } else {
            $lines = explode(PHP_EOL, $text);
            while ($lines) {
                $line = array_shift($lines);
                if ($lines || $line !== "") {
                    if ($sol) {
                        if ($timestamp) {
                            echo "[" . date("c") . "] ";
                        }
                        $indent = $this->logIndent(0);
                        echo str_pad("", ($indent * 4), "    ");
                    }

                    if ($lines) {
                        echo $line . PHP_EOL;
                        $sol = true;
                    } else {
                        echo $line;
                        $sol = false;
                    }
                }
            }

            if ($append_eol) {
                if ($timestamp && $sol) {
                    echo "[" . date("c") . "] ";
                }
                echo PHP_EOL;
                $sol = true;
            }
        }
    }

    public function logIndent($size = 1)
    {
        static $indent = 0;

        if (is_int($size)) {
            $indent += $size;
        } else if ($size === false) {
            $indent = 0;
        }

        if ($indent <= 0) {
            $indent = 0;
        }

        return $indent;
    }
 
    public function logUnindent()
    {
        return $this->logIndent(-1);
    }

    public function prompt($message, $answer_regex = "#.*#")
    {
        $answer = "";

        do {
            $this->log($message, false);
            $answer = readline();
            $this->log("<#SOL#>");
        } while (!preg_match($answer_regex, $answer));

        return $answer;
    }

    protected function _getConfigDefaults()
    {
        return [
            "arguments" => [
                "debug" => [
                    "short" => "d",
                    "description" => "Enable additional logging and prompts.",
                ],
                "email" => [
                    "short" => "e",
                    "description" => "Email address for notifications, such as unrecoverable errors."
                ],
                "help" => [
                    "short" => "h",
                    "description" => "Display script options and details (this output)."
                ],
                "max_processes" => [
                    "description" => "The maximum number of concurrent running instances of a process-category combination."
                ],
                "pid_category" => [
                    "description" => "Categorizes processes for use in conjunction with max_processes.",
                ]
            ]
        ];
    }

    protected function _getHelpText()
    {
        $text  = "#\n";
        $text .= "# NAME\n";
        $text .= "#\n";
        $text .= "#     " . $this->_config["name"] . "\n";
        $text .= "#\n";
        $text .= "# SUMMARY\n";
        $text .= "#\n";
        $summary = isset($this->_config["summary"]) ?
            $this->_config["summary"] : "(no summary provided)";
        $text .= "#     {$summary}\n";
        $text .= "#\n";
        $text .= "# ARGUMENTS\n";
        $text .= "#\n";
        foreach ($this->_config["arguments"] as $arg => $arg_def) {
            if (strlen($arg) === 1) {
                $text .= "#     -{$arg}";
            } else {
                $text .= "#     --{$arg}";
                if (isset($arg_def["short"])) {
                    if (strlen($arg_def["short"]) === 1) {
                        $short = $arg_def["short"];
                        $text .= ", -{$short}";
                    }
                }
            }
            $text .= "\n";
            $text .= "#\n";
            $description = isset($arg_def["description"]) ?
                $arg_def["description"] : "(no description provided)";
            $text .= "#         {$description}\n";
            $text .= "#\n";
        }
        return $text;
    }

    protected function _logFooter()
    {
        $this->log("", true, false);
    }

    protected function _logHeader()
    {
        $this->log("", true, false);
    }

    protected function _reportError($error, $unrecoverable = false)
    {
        if (is_array($error)) {
            $error = nl2br(var_export($error, true));
        } else if ($error instanceof Exception) {
            $error = nl2br($error);
        }

        $host = gethostname();
        $execution_env = "{$host}: {$this->_executedFile}";

        if ($unrecoverable) {
            $subject = "SCRIPT FAILURE!!! ";
        } else {
            $subject = "SCRIPT ERROR!!! ";
        }
        $subject .= "[{$execution_env}]";

        $content  = "<p>";
        if ($unrecoverable) {
            $content .= "An UNRECOVERABLE failure occurred while executing ";
        } else {
            $content .= "An error occurred while executing ";
        }
        $content .= "'{$this->_executedFile}' on '{$host}'.  The following ";
        if ($unrecoverable) {
            $content .= "are the details of this failure:";
        } else {
            $content .= "are the details of this error:";
        }
        $content .= "</p>";
        $content .= "<p style='margin-left: 30px'>{$error}</p>";

        $content .= "<p>";
        if ($unrecoverable) {
            $content .= "The script terminated unexpectedly as a result of this failure.";
        } else {
            $content .= "The script continued after this error and may have ";
            $content .= "reached a succesful completion state.";
        }
        $content .= "</p>";
        $this->email($content, $subject);
    }

    protected function _reportFailure($error)
    {
        $this->log();
        $this->log("Notifying concerned parties ... ", false);
        $this->_reportError($error, true);
        $this->log("DONE!");
    }

    protected function _reportSuccess()
    {
    }

    protected function _run()
    {
    }

    protected function _validateArgument($argument, $value)
    {
        $errors = [];

        $long_argument = $this->_getLongArgument($argument);
        if (is_null($long_argument) && preg_match("#[A-Za-z]#", $argument)) {
            $errors[] = "\"$argument\" is not a recognized option.";
        }

        return $errors;
    }

    private function _updatePidFile($shutdown = false)
    {
        $user_pid_dir = static::PID_DIR . "/" . get_current_user() . "/";
        if (!file_exists($user_pid_dir)) {
            mkdir($user_pid_dir, 0777, true);
        }
        $this->_pidFile = $user_pid_dir . $this->_config["name"] . "." . 
            $this->_getPidCategory() . ".pid";

        $active_pids = [];
        if (file_exists($this->_pidFile)) {
            $pids = json_decode(file_get_contents($this->_pidFile), true);
            foreach ($pids as $pid) {
                if ($pid !== getmypid() && posix_getpgid($pid)) {
                    $active_pids[] = $pid;
                }
            }
        }

        if (!$shutdown) {
            if (isset($this->_arguments["max_processes"])) {
                if (count($active_pids) + 1 <= $this->_arguments["max_processes"]) {
                    $active_pids[] = getmypid();
                } else {
                    $active_pids = false;
                }
            } else {
                $active_pids[] = getmypid();
            }
        }

        if ($active_pids !== false) {
            if ($active_pids) {
                file_put_contents($this->_pidFile, json_encode($active_pids));
            } else {
                unlink($this->_pidFile);
            }
        }

        return (is_array($active_pids) ? count($active_pids) : false);
    }

    private function _getLongArgument($argument)
    {
        $long_argument = null;

        if (array_key_exists($argument, $this->_config["arguments"])) {
            $long_argument = $argument;
        } else {
            foreach ($this->_config["arguments"] as $arg => $arg_def) {
                if (isset($arg_def["short"])) {
                    if ($argument === $arg_def["short"]) {
                        $long_argument = $arg;
                        break;
                    }
                }
            }
        }

        return $long_argument;
    }

    private function _getPidCategory()
    {
        $pid_category = null;
        if (isset($this->_arguments["pid_category"])) {
            $pid_category = $this->_arguments["pid_category"];
        } else {
            ksort($this->_arguments);
            $pid_category = sha1(json_encode($this->_arguments));
        }
        return $pid_category;
    }

    private function _parseCliArguments($arguments)
    {
        $parsed_arguments = [];

        $position = 0;
        $keys = null;
        foreach ($arguments as $arg) {
            $value = null;
            if (preg_match("#^--(?<keys>.+)#", $arg, $matches)) {
                $keys = [$matches["keys"]];
            } else if (preg_match("#^-(?<keys>[A-Za-z]+)#", $arg, $matches)) {
                $keys = str_split($matches["keys"]);
                $value = true;
            } else {
                $value = $arg;
                // $parsed_arguments[$position] = $value;
                $position++;
            }

            if ($keys) {
                foreach ($keys as $key) {
                    if (array_key_exists($key, $parsed_arguments)) {
                        if (
                            $parsed_arguments[$key] === true ||
                            is_null($parsed_arguments[$key])
                        ) {
                            $parsed_arguments[$key] = $value;
                        } else if (is_array($parsed_arguments[$key])) {
                            $parsed_arguments[$key][] = $value;
                        } else {
                            $parsed_arguments[$key] = [
                                $parsed_arguments[$key], $value
                            ];
                        }
                    } else {
                        $parsed_arguments[$key] = $value;
                    }
                }
            }
        }

        return $parsed_arguments;
    }

    private function _setArgument($argument, $value)
    {
        if (is_string($value)) {
            switch ($value) {
                case "null": case "NULL":
                    $value = null;
                    break;
                case "true": case "TRUE":
                    $value = true;
                    break;
                case "false": case "FALSE":
                    $value = false;
                    break;
            }
        }

        $this->_arguments[$argument] = $value;

        $long_argument = $this->_getLongArgument($argument);
        if ($long_argument) {
            $this->_arguments[$long_argument] = $value;
            if (isset($this->_config["arguments"][$long_argument]["short"])) {
                $short = $this->_config["arguments"][$long_argument]["short"];
                $this->_arguments[$short] = $value;
            }
        }
    }
}
