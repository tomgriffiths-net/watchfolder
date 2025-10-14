<?php
class watchfolder{
    public static function command($line):void{
        $lines = explode(" ", $line);

        if(strtolower($lines[0]) === "start"){
            self::start();
        }
        else{
            echo "Unknown command\nUsage: watchfolder start\n";
        }
    }
    public static function init():void{
        $defaultSettings = [
            "watchers" => [],
            "processFilesPresentAtStartup" => true,
            "retryFailedFilesOnNextPass" => false,
            "saveState" => true,
            "refreshSettings" => true,
            "refreshSettingsInterval" => 300,
            "enableSettingsRefreshFile" => true,
            "useOldSettingsOnReadFailure" => true
        ];

        foreach($defaultSettings as $settingName => $defaultValue){
            settings::set($settingName, $defaultValue, false);
        }
    }
    public static function start():void{
        mklog(1, 'Starting watchfolder watching process');

        $watchers = self::getWatcherSettings();
        if(!is_array($watchers)){
            mklog(2, 'Failed to get watchers data');
            return;
        }
        $lastSettingsRead = time();
        
        self::initWatchersData($watchers);

        while(true){
            foreach($watchers as $watcherName => $doNotUseThisVariable){
                if(time() - $watchers[$watcherName]['lastCheckTime'] < $watchers[$watcherName]['interval'] || !$watchers[$watcherName]['active']){
                    continue;
                }

                self::watchOnce($watchers[$watcherName], $watcherName);

                if(settings::read('saveState')){
                    settings::set('watchers/' . $watcherName . '/knownFiles', $watchers[$watcherName]['knownFiles'], true);
                    settings::set('watchers/' . $watcherName . '/lastCheckTime', $watchers[$watcherName]['lastCheckTime'], true);
                }
            }

            if((settings::read('refreshSettings') && time() - $lastSettingsRead > settings::read('refreshSettingsInterval')) || (settings::read('enableSettingsRefreshFile') && is_file("temp/watchfolder/refreshsettings"))){
                $watchers = self::getWatcherSettings();
                if(is_array($watchers)){
                    $lastSettingsRead = time();
                    if(is_file("temp/watchfolder/refreshsettings")){
                        unlink("temp/watchfolder/refreshsettings");
                    }
                }
                elseif(settings::read('useOldSettingsOnReadFailure')){
                    mklog(2, 'Failed to get refresh watchers data, using old data');
                }
                else{
                    mklog(2, 'Failed to get refresh watchers data, closing watcher process');
                    return;
                }
            }

            sleep(5);
        }
    }
    private static function runAction(array $action, array $vars, string $identifier):mixed{
        if(!isset($action['type'])){
            mklog(2, 'Action ' . $identifier . ' does not have a type');
            return false;
        }

        mklog(0, "Running action " . $identifier);

        if($action['type'] === "codeString"){
            if(!self::validateActionInputs($action, ["code"=>"string"], $identifier)){
                return false;
            }

            try{
                $result = eval("return " . self::replaceStringWithVars($action['code'], $vars) . ";");
            }
            catch(Throwable $throwable){
                mklog(2, "Failed to run code string for action " . $identifier . "(" . substr($throwable,0,strpos($throwable,"\n")) . ")");
                $result = false;
            }

            return $result;
        }
        elseif($action['type'] === "if"){
            if(!self::validateActionInputs($action, ["testActions"=>"array","positiveActions"=>"array","negativeActions"=>"array"], $identifier)){
                return false;
            }

            if(self::runActions($action["testActions"], $vars, $identifier . "-ifTest")){
                return self::runActions($action["positiveActions"], $vars, $identifier . "-ifPositive");
            }
            else{
                return self::runActions($action["negativeActions"], $vars, $identifier . "-ifNegative");
            }
        }
        elseif($action['type'] === "isFile"){
            if(!self::validateActionInputs($action, ["fileName"=>"string","positiveActions"=>"array","negativeActions"=>"array"], $identifier)){
                return false;
            }

            if(is_file(self::replaceStringWithVars($action['fileName'], $vars))){
                return self::runActions($action["positiveActions"], $vars, $identifier . "-isFilePositive");
            }
            else{
                return self::runActions($action["negativeActions"], $vars, $identifier . "-isFileNegative");
            }
        }
        elseif($action['type'] === "copyFile"){
            if(!self::validateActionInputs($action, ["source"=>"string","destination"=>"string"], $identifier)){
                return false;
            }

            return files::copyFile(self::replaceStringWithVars($action['source'], $vars), self::replaceStringWithVars($action['destination'], $vars));
        }
        elseif($action['type'] === "deleteFile"){
            if(!self::validateActionInputs($action, ["file"=>"string"], $identifier)){
                return false;
            }

            return unlink(self::replaceStringWithVars($action['file'], $vars));
        }
        elseif($action['type'] === "logMessage"){
            if(!self::validateActionInputs($action, ["message"=>"string","level"=>"integer"], $identifier)){
                return false;
            }

            mklog($action["level"], "CustomMessage: " . self::replaceStringWithVars($action["message"], $vars));
            return true;
        }
        elseif($action['type'] === "ffmpegCommand"){
            if(!self::validateActionInputs($action, ["source"=>"string","destination"=>"string","args"=>"string","allowFileCopy"=>"boolean"], $identifier)){
                return false;
            }

            if(!class_exists('video_encoder')){
                mklog(2, 'Cannot run action type ffmpegCommand as the video_encoder package is not installed');
                return false;
            }

            return video_encoder::encode_video(self::replaceStringWithVars($action['source'], $vars), self::replaceStringWithVars($action['destination'], $vars), ["customArgs"=> self::replaceStringWithVars($action['args'], $vars)], $action['allowFileCopy']);
        }
        elseif($action['type'] === "runCommand"){
            if(!self::validateActionInputs($action, ["command"=>"string"], $identifier)){
                return false;
            }

            if(strtolower($action["command"]) === "exit"){
                return false;
            }

            return cli::run(self::replaceStringWithVars($action["command"], $vars));
        }
        else{
            mklog(2, 'Action ' . $identifier . ' has an unknown type');
            return false;
        }
    }
    private static function runActions(array $actions, array $vars, string $identifier):bool{
        foreach($actions as $actionId => $action){
            if(!is_int($actionId) || !is_array($action)){
                mklog(2, 'Encountered invalid actions structure at ' . $identifier);
                return false;
            }
            if(!self::runAction($action, $vars, $identifier . "-" . $actionId)){
                return false;
            }
        }
        return true;
    }
    private static function replaceStringWithVars(string $string, array $vars):string{
        foreach($vars as $varName => $varValue){
            $string = str_replace("<" . $varName . ">", $varValue, $string);
        }
        return $string;
    }
    private static function validateActionInputs(array $action, array $expected, string $identifier):bool{
        if(!self::validateData($action, $expected)){
            mklog(2, 'Action ' . $identifier . ' has a missing or invalid parameter');
            return false;
        }
        return true;
    }
    private static function validateData(array $data, array $expected):bool{
        foreach($expected as $expectedName => $expectedType){
            if(!isset($data[$expectedName]) || gettype($data[$expectedName]) !== $expectedType){
                return false;
            }
        }
        return true;
    }
    private static function getFiles(string $directory, array $types):array|false{
        if(!is_dir($directory)){
            mklog(2, 'Failed to read from nonexistant directory ' . $directory);
            return false;
        }

        $directory = realpath($directory);
        if(!is_string($directory)){
            mklog(2, 'Failed to get real path for ' . $directory);
            return false;
        }

        $files = scandir($directory);
        if(!is_array($files)){
            mklog(2, 'Failed to get files from directory ' . $directory);
            return false;
        }

        $directory = preg_replace('/\/+/', '\\', $directory);
        
        foreach($files as $fileNumber => $fileName){
            if(!in_array(strtolower(files::getFileExtension($fileName)), $types)){
                unset($files[$fileNumber]);
                continue;
            }

            $files[$fileNumber] = $directory . "\\" . $fileName;
        }

        return $files;
    }
    private static function watchOnce(array &$watcher, string $watcherName):void{
        $watcher['lastCheckTime'] = time();

        $fileActions = self::getFileActions($watcher);
        if(!is_array($fileActions)){
            mklog(2, 'Failed to get list of file changes for watcher ' . $watcherName);
            return;
        }

        foreach($fileActions as $file => $creation){
            $baseName = basename($file);
            mklog(1, "Processing file " . $baseName . ($creation ? " creation" : " deletion"));

            $vars = [
                "file" => $file,
                "tempFile" => getcwd() . "\\temp\\watchfolder\\" . time::millistamp(),
            ];
            //Add escaped options for paths
            foreach(["file","tempFile"] as $var){$vars[$var . 'Esc'] = str_replace("\\", "\\\\", $vars[$var]);}

            if($creation){
                $actionsResult = self::runActions($watcher['creationActions'], $vars, $watcherName . "-creation");
            }
            else{
                $actionsResult = self::runActions($watcher['deletionActions'], $vars, $watcherName . "-deletion");
            }

            $tempFiles = glob($vars['tempFile'] . "*");
            foreach($tempFiles as $tempFile){
                @unlink($tempFile);
            }

            if(!$creation){
                if(!$actionsResult){
                    mklog(2, 'Failed to run deletionActions for watcher ' . $watcherName);
                }
                continue;
            }

            if($actionsResult){
                $watcher['knownFiles'][$file]['processed'] = true;
            }
            else{
                $processAgain = settings::read('retryFailedFilesOnNextPass');
                mklog(2, 'Failed to run actions for watcher ' . $watcherName . ' on file ' . $file . ($processAgain ? ', will try again later' : ''));
                if(!$processAgain){
                    $watcher['knownFiles'][$file]['processed'] = true;
                }
            }
        }
    }
    private static function getFileActions(array &$watcher):array|false{
        $currentFiles = self::getFiles($watcher['directory'], $watcher['fileTypes']);
        if(!is_array($currentFiles)){
            mklog(2, 'Failed to get files list for ' . $watcher['directory']);
            return false;
        }

        $fileActions = [];

        //deletion detection
        foreach($watcher['knownFiles'] as $knownFile => $knownFileStats){
            if(!in_array($knownFile, $currentFiles)){
                $fileActions[$knownFile] = false;
                unset($watcher['knownFiles'][$knownFile]);
            }
        }

        foreach($currentFiles as $currentFile){
            $baseName = basename($currentFile);

            if(isset($watcher['knownFiles'][$currentFile]) && $watcher['knownFiles'][$currentFile]['processed']){
                continue;
            }

            clearstatcache(true, $currentFile);
            $currentSize = filesize($currentFile);
            if(!is_int($currentSize)){
                mklog(2, 'Failed to read file size of ' . $currentFile);
                continue;
            }

            if(!isset($watcher['knownFiles'][$currentFile])){
                mklog(0, "Found new file: " . $baseName);

                $watcher['knownFiles'][$currentFile] = [
                    "processed" => false,
                    "stableChecks" => 0,
                    "lastFileSize" => $currentSize
                ];

                continue;
            }

            if($watcher['knownFiles'][$currentFile]['lastFileSize'] !== $currentSize){
                $watcher['knownFiles'][$currentFile]['lastFileSize'] = $currentSize;
                $watcher['knownFiles'][$currentFile]['stableChecks'] = 0;
                continue;
            }

            //size matches at this point
            $watcher['knownFiles'][$currentFile]['stableChecks']++;

            if($watcher['knownFiles'][$currentFile]['stableChecks'] < $watcher['stableCount']){
                continue;
            }

            $fileActions[$currentFile] = true;
        }

        return $fileActions;
    }
    private static function getWatcherSettings():array|false{
        $watchers = settings::read('watchers');
        if(!is_array($watchers)){
            mklog(2, 'Failed to read watchers settings');
            return false;
        }
        foreach($watchers as $watcherName => $watcher){
            if(!is_string($watcherName) || !is_array($watcher)){
                mklog(2, 'Found invalid data in watchers');
                return false;
            }

            if(!self::validateData($watcher, [
                "directory" => "string",
                "fileTypes" => "array",
                "interval" => "integer",
                "stableCount" => "integer",
                "active" => "boolean",
                "creationActions" => "array",
                "deletionActions" => "array"
            ])){
                mklog(2, 'Watcher ' . $watcherName . ' has missing settings, setting it to inactive');
                $watchers[$watcherName]['active'] = false;
                settings::set('watchers/' . $watcherName . '/active', false, true);
            }
        }

        return $watchers;
    }
    private static function initWatchersData(array &$watchers):void{
        foreach($watchers as $watcherName => $doNotUseThisVariable){
            if(!settings::read("saveState")){
                unset($watchers[$watcherName]['knownFiles']);
                unset($watchers[$watcherName]['lastCheckTime']);
            }

            if(!isset($watchers[$watcherName]['knownFiles']) || !is_array($watchers[$watcherName]['knownFiles'])){
                $watchers[$watcherName]['knownFiles'] = [];

                if(!settings::read('processFilesPresentAtStartup')){ // pre fill knownFiles so files present are not processed or seen as new

                    $startupFiles = self::getFiles($watchers[$watcherName]['directory'], $watchers[$watcherName]['fileTypes']);
                    if(!is_array($startupFiles)){
                        mklog(2, 'Failed to read files from ' . $watchers[$watcherName]['directory']);
                        continue;
                    }

                    foreach($startupFiles as $startupFile){
                        $watchers[$watcherName]['knownFiles'][$startupFile] = [
                            "processed" => true,
                            "stableChecks" => 0,
                            "lastFileSize" => filesize($startupFile)
                        ];
                    }
                }
            }

            if(!isset($watchers[$watcherName]['lastCheckTime']) || !is_int($watchers[$watcherName]['lastCheckTime'])){
                if(settings::read('processFilesPresentAtStartup')){
                    $watchers[$watcherName]['lastCheckTime'] = 0;
                }
                else{
                    $watchers[$watcherName]['lastCheckTime'] = time();
                }
            }
        }
    }
}