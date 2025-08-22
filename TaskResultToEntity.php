<?php
// Настройка логирования
function logToFile($data)
{
    $logFile = __DIR__ . '/task_result_to_entity.txt';
    $current = file_get_contents($logFile);
    $current .= date('Y-m-d H:i:s') . " - " . print_r($data, true) . "\n";
    file_put_contents($logFile, $current);
}

// Получение данных из POST-запроса
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Если JSON декодирование не сработало, пробуем parse_str для обратной совместимости
if ($data === null) {
    parse_str($input, $data);
}

// Проверка обязательных полей
if (
    !isset($data['auth']['access_token']) || !isset($data['auth']['domain']) ||
    !isset($data['properties']['task_id'])
) {
    logToFile('Ошибка: Не хватает обязательных полей в запросе');
    http_response_code(400);
    echo json_encode(['error' => 'Требуемые поля: access_token, domain, task_id']);
    exit;
}

// Параметры запроса
$access_token = $data['auth']['access_token'];
$domain = $data['auth']['domain'];
$task_id = intval($data['properties']['task_id']);
$eventToken = isset($data['event_token']) ? $data['event_token'] : null;

// Логирование начала работы
logToFile([
    'action' => 'start',
    'task_id' => $task_id,
    'event_token' => $eventToken
]);

// Функция вызова Bitrix24 API
function callB24Api($method, $params, $access_token, $domain)
{
    $url = "https://{$domain}/rest/{$method}?auth={$access_token}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        logToFile('CURL Error: ' . curl_error($ch));
        return false;
    }
    curl_close($ch);
    return json_decode($response, true);
}

// Функция получения корневой папки общего диска
function getCommonDiskRoot($access_token, $domain)
{
    // Получаем список всех дисков
    $storages = callB24Api('disk.storage.getlist', [], $access_token, $domain);
    
    if (!$storages || !isset($storages['result'])) {
        logToFile('Ошибка: Не удалось получить список дисков');
        return false;
    }
    
    logToFile(['all_storages' => $storages['result']]);
    
    // Ищем общий диск (обычно имеет тип 'common')
    $commonStorage = null;
    foreach ($storages['result'] as $storage) {
        if ($storage['ENTITY_TYPE'] === 'common') {
            $commonStorage = $storage;
            break;
        }
    }
    
    // Если общий диск не найден, используем первый доступный
    if (!$commonStorage && !empty($storages['result'])) {
        $commonStorage = $storages['result'][0];
        logToFile(['using_first_storage' => $commonStorage['ID']]);
    }
    
    if (!$commonStorage) {
        logToFile('Ошибка: Нет доступных дисков');
        return false;
    }
    
    logToFile(['selected_storage' => $commonStorage]);
    
    // Если ROOT_FOLDER_ID пустой, получаем корневую папку через другой метод
    if (empty($commonStorage['ROOT_FOLDER_ID'])) {
        logToFile('ROOT_FOLDER_ID пустой, получаем через disk.storage.get');
        
        $storageDetails = callB24Api('disk.storage.get', ['id' => $commonStorage['ID']], $access_token, $domain);
        
        if ($storageDetails && isset($storageDetails['result']['ROOT_FOLDER_ID'])) {
            $rootFolderId = $storageDetails['result']['ROOT_FOLDER_ID'];
            logToFile(['root_folder_from_details' => $rootFolderId]);
            return $rootFolderId;
        }
        
        // Если и это не сработало, попробуем получить список папок
        logToFile('Попытка получить корневую папку через disk.folder.getchildren');
        
        $folders = callB24Api('disk.folder.getchildren', ['id' => $commonStorage['ID']], $access_token, $domain);
        
        if ($folders && isset($folders['result'])) {
            // Используем ID самого диска как папку
            logToFile(['using_storage_id_as_folder' => $commonStorage['ID']]);
            return $commonStorage['ID'];
        }
        
        logToFile('Все методы получения корневой папки не сработали');
        return false;
    }
    
    logToFile(['common_disk_found' => $commonStorage['ID'], 'root_folder' => $commonStorage['ROOT_FOLDER_ID']]);
    return $commonStorage['ROOT_FOLDER_ID'];
}

// Функция загрузки файла на диск через UploadUrl
function uploadFileToDisk($folderId, $fileContent, $fileName, $access_token, $domain)
{
    // Получаем URL для загрузки
    $uploadRequest = callB24Api('disk.folder.uploadfile', [
        'id' => $folderId
    ], $access_token, $domain);
    
    if (!$uploadRequest || !isset($uploadRequest['result']['uploadUrl'])) {
        logToFile('Ошибка: Не удалось получить uploadUrl');
        return false;
    }
    
    $uploadUrl = $uploadRequest['result']['uploadUrl'];
    $fieldName = $uploadRequest['result']['field'];
    
    logToFile(['upload_url_received' => $uploadUrl, 'field_name' => $fieldName]);
    
    // Подготавливаем multipart/form-data
    $delimiter = '-------------' . uniqid('', true);
    $mime = 'application/octet-stream';
    
    $body = '--' . $delimiter . "\r\n";
    $body .= 'Content-Disposition: form-data; name="' . $fieldName . '"';
    $body .= '; filename="' . $fileName . '"' . "\r\n";
    $body .= 'Content-Type: ' . $mime . "\r\n\r\n";
    $body .= $fileContent . "\r\n";
    $body .= "--" . $delimiter . "--\r\n";
    
    // Отправляем файл
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $uploadUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: multipart/form-data; boundary=' . $delimiter,
        'Content-Length: ' . strlen($body),
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        logToFile('CURL Upload Error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result && isset($result['result']['ID'])) {
        logToFile(['file_uploaded_successfully' => $result['result']['ID'], 'file_name' => $fileName]);
        return $result['result']['ID'];
    } else {
        logToFile(['upload_error' => $result]);
        return false;
    }
}

// Функция получения содержимого файла через Bitrix24 API
function getFileContent($fileId, $access_token, $domain)
{
    // Получаем информацию о файле включая download URL
    $fileInfo = callB24Api('disk.file.get', ['id' => $fileId], $access_token, $domain);
    
    if (!$fileInfo || !isset($fileInfo['result']['DOWNLOAD_URL'])) {
        logToFile(['file_info_error' => 'Не удалось получить информацию о файле', 'file_id' => $fileId]);
        return false;
    }
    
    $downloadUrl = $fileInfo['result']['DOWNLOAD_URL'];
    $fileName = $fileInfo['result']['NAME'];
    
    // Скачиваем содержимое файла
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $downloadUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $content = curl_exec($ch);
    
    if (curl_errno($ch)) {
        logToFile('CURL Download Error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    
    return ['content' => $content, 'name' => $fileName];
}

// Функция отправки результата в бизнес-процесс
function sendBizprocResult($eventToken, $returnValues, $access_token, $domain)
{
    if (!$eventToken) {
        logToFile('Предупреждение: event_token не найден, результат не отправлен в БП');
        return true;
    }
    
    return callB24Api(
        'bizproc.event.send',
        [
            'event_token' => $eventToken,
            'return_values' => $returnValues
        ],
        $access_token,
        $domain
    );
}

try {
    // 1. Получаем данные задачи
    $task = callB24Api("tasks.task.get", ['taskId' => $task_id], $access_token, $domain);
    if (!$task || !isset($task['result']['task'])) {
        logToFile("Ошибка: Задача #{$task_id} не найдена");
        
        $returnValues = [
            'files' => '',
            'text' => ''
        ];
        
        sendBizprocResult($eventToken, $returnValues, $access_token, $domain);
        
        http_response_code(404);
        echo json_encode(['error' => "Задача #{$task_id} не найдена"]);
        exit;
    }

    $taskData = $task['result']['task'];
    logToFile(['task_data_received' => $task_id]);

    // 2. Получаем корневую папку общего диска
    $commonDiskRootId = getCommonDiskRoot($access_token, $domain);
    if (!$commonDiskRootId) {
        logToFile('Ошибка: Не удалось найти общий диск');
        throw new Exception('Не удалось найти общий диск');
    }

    // 3. Получаем результаты задачи
    $taskResults = callB24Api("tasks.task.result.list", ['taskId' => $task_id], $access_token, $domain);
    if (!$taskResults || !isset($taskResults['result'])) {
        logToFile("Предупреждение: Не удалось получить результаты задачи #{$task_id}");
        $taskResults = ['result' => []];
    }

    $results = $taskResults['result'];
    logToFile(['results_count' => count($results)]);

    // 4. Обрабатываем результаты
    $newFileIds = [];
    $textResult = '';

    if (!empty($results)) {
        $result = $results[0]; // Берем первый результат

        // Обрабатываем файлы
        if (!empty($result['files']) && is_array($result['files'])) {
            $originalFileIds = $result['files'];
            logToFile(['original_file_ids' => $originalFileIds]);
            
            // Получаем реальные FILE_ID через комментарий, если есть
            $realFileIds = $originalFileIds;
            if (!empty($result['commentId'])) {
                $commentInfo = callB24Api("task.commentitem.get", [
                    'TASKID' => $task_id,
                    'ITEMID' => $result['commentId']
                ], $access_token, $domain);
                
                if ($commentInfo && isset($commentInfo['result']['ATTACHED_OBJECTS'])) {
                    $foundRealIds = [];
                    foreach ($commentInfo['result']['ATTACHED_OBJECTS'] as $attachedFile) {
                        if (isset($attachedFile['FILE_ID'])) {
                            $foundRealIds[] = $attachedFile['FILE_ID'];
                        }
                    }
                    
                    if (!empty($foundRealIds)) {
                        $realFileIds = $foundRealIds;
                        logToFile(['real_file_ids_found' => $realFileIds]);
                    }
                }
            }
            
            // Перезагружаем каждый файл на общий диск
            foreach ($realFileIds as $fileId) {
                $fileData = getFileContent($fileId, $access_token, $domain);
                
                if ($fileData) {
                    $newFileId = uploadFileToDisk(
                        $commonDiskRootId,
                        $fileData['content'],
                        $fileData['name'],
                        $access_token,
                        $domain
                    );
                    
                    if ($newFileId) {
                        $newFileIds[] = $newFileId;
                        logToFile(['file_reuploaded' => ['original_id' => $fileId, 'new_id' => $newFileId, 'name' => $fileData['name']]]);
                    } else {
                        logToFile(['file_reupload_failed' => $fileId]);
                    }
                } else {
                    logToFile(['file_content_failed' => $fileId]);
                }
            }
        }

        // Получаем текстовый результат
        if (!empty($result['text'])) {
            $textResult = $result['text'];
            logToFile(['text_result' => $textResult]);
        }
    }

    // Если нет результатов, используем описание задачи
    if (empty($newFileIds) && empty($textResult)) {
        $textResult = !empty($taskData['DESCRIPTION']) ? $taskData['DESCRIPTION'] : '';
        logToFile(['fallback_to_description' => $textResult]);
    }

    // 5. Формируем возвращаемые значения
    $returnValues = [
        'files' => implode(',', $newFileIds), // Массив ID новых файлов через запятую
        'text' => $textResult
    ];

    // 6. Отправляем результат в бизнес-процесс
    $bizprocResult = sendBizprocResult($eventToken, $returnValues, $access_token, $domain);

    // 7. Формируем ответ
    $response = [
        'success' => true,
        'message' => 'Результат задачи успешно получен и файлы загружены на общий диск',
        'files' => $newFileIds,
        'text' => $textResult,
        'uploaded_to_common_disk' => true
    ];

    logToFile(['success' => $response]);
    echo json_encode($response);

} catch (Exception $e) {
    // Обработка исключений
    $errorMessage = 'Внутренняя ошибка сервера: ' . $e->getMessage();
    
    $returnValues = [
        'files' => '',
        'text' => ''
    ];
    
    sendBizprocResult($eventToken, $returnValues, $access_token, $domain);

    logToFile(['exception' => $errorMessage, 'trace' => $e->getTraceAsString()]);
    http_response_code(500);
    echo json_encode(['error' => $errorMessage]);
}
?>