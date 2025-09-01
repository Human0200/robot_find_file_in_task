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
    !isset($data['properties']['task_id']) || !isset($data['properties']['entity_type']) ||
    !isset($data['properties']['entity_id']) || !isset($data['properties']['field_code'])
) {
    //logToFile('Ошибка: Не хватает обязательных полей в запросе');
    http_response_code(400);
    echo json_encode(['error' => 'Требуемые поля: access_token, domain, task_id, entity_type, entity_id, field_code']);
    exit;
}

// Параметры запроса
$access_token = $data['auth']['access_token'];
$domain = $data['auth']['domain'];
$task_id = intval($data['properties']['task_id']);
$entity_type = $data['properties']['entity_type'];
$entity_id = intval($data['properties']['entity_id']);
$field_code = $data['properties']['field_code'];
$smart_process_id = isset($data['properties']['smart_process_id']) ? intval($data['properties']['smart_process_id']) : null;
$eventToken = isset($data['event_token']) ? $data['event_token'] : null;

// Логирование начала работы
logToFile([
    'action' => 'start',
    'task_id' => $task_id,
    'entity_type' => $entity_type,
    'entity_id' => $entity_id,
    'field_code' => $field_code,
    'smart_process_id' => $smart_process_id,
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
        //logToFile('CURL Error: ' . curl_error($ch));
        return false;
    }
    curl_close($ch);
    return json_decode($response, true);
}

// Функция получения содержимого файла через Bitrix24 API
function getFileContent($fileId, $access_token, $domain)
{
    // Получаем информацию о файле включая download URL
    $fileInfo = callB24Api('disk.file.get', ['id' => $fileId], $access_token, $domain);
    
    if (!$fileInfo || !isset($fileInfo['result']['DOWNLOAD_URL'])) {
        //logToFile(['file_info_error' => 'Не удалось получить информацию о файле', 'file_id' => $fileId]);
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
        //logToFile('CURL Download Error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    
    return ['content' => $content, 'name' => $fileName];
}

// Функция обновления сущности
function updateEntity($entity_type, $entity_id, $field_code, $fileIds, $smart_process_id, $access_token, $domain)
{
    $method = '';
    
    // Преобразуем массив ID файлов в правильный формат для Bitrix24
    $fileValues = [];
    foreach ($fileIds as $fileId) {
        $fileData = getFileContent($fileId, $access_token, $domain);
        if ($fileData) {
            $fileValues[] = [
                $fileData['name'],
                base64_encode($fileData['content'])
            ];
            //logToFile(['file_prepared_for_upload' => ['id' => $fileId, 'name' => $fileData['name'], 'size' => strlen($fileData['content'])]]);
        } else {
            //logToFile(['file_preparation_failed' => $fileId]);
        }
    }
    
    if (empty($fileValues)) {
        //logToFile('Нет файлов для записи после обработки');
        return false;
    }
    
    // Если файл только один, передаем его как одиночный файл (для не множественных полей)
    // Если файлов несколько, передаем как массив (для множественных полей)
    $fieldValue = (count($fileValues) === 1) ? $fileValues[0] : $fileValues;
    
    $params = [
        'id' => $entity_id,
        'fields' => [
            $field_code => $fieldValue
        ]
    ];
    
    // Определяем метод API в зависимости от типа сущности
    switch ($entity_type) {
        case 'lead':
            $method = 'crm.lead.update';
            break;
        case 'contact':
            $method = 'crm.contact.update';
            break;
        case 'company':
            $method = 'crm.company.update';
            break;
        case 'deal':
            $method = 'crm.deal.update';
            break;
        case 'smart_process':
            if (!$smart_process_id) {
                //logToFile('Ошибка: Для смарт-процесса необходимо указать smart_process_id');
                return false;
            }
            $method = "crm.item.update";
            $params['entityTypeId'] = $smart_process_id;
            break;
        default:
            //logToFile(['unsupported_entity_type' => $entity_type]);
            return false;
    }
    
    logToFile(['update_entity_request' => [
        'method' => $method,
        'entity_id' => $entity_id,
        'field_code' => $field_code,
        'files_count' => count($fileValues),
        'is_single_file' => (count($fileValues) === 1),
        'field_value_type' => (count($fileValues) === 1) ? 'single_file' : 'multiple_files'
    ]]);
    
    $result = callB24Api($method, $params, $access_token, $domain);
    
    if ($result && isset($result['result'])) {
        //logToFile(['entity_updated_successfully' => $result['result']]);
        return true;
    } else {
        //logToFile(['entity_update_error' => $result]);
        return false;
    }
}

// Функция отправки результата в бизнес-процесс
function sendBizprocResult($eventToken, $returnValues, $access_token, $domain)
{
    if (!$eventToken) {
        //logToFile('Предупреждение: event_token не найден, результат не отправлен в БП');
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
        //logToFile("Ошибка: Задача #{$task_id} не найдена");
        
        $returnValues = [
            'success' => false,
            'message' => "Задача #{$task_id} не найдена"
        ];
        
        sendBizprocResult($eventToken, $returnValues, $access_token, $domain);
        
        http_response_code(404);
        echo json_encode(['error' => "Задача #{$task_id} не найдена"]);
        exit;
    }

    $taskData = $task['result']['task'];
    //logToFile(['task_data_received' => $task_id]);

    // 2. Получаем результаты задачи
    $taskResults = callB24Api("tasks.task.result.list", ['taskId' => $task_id], $access_token, $domain);
    if (!$taskResults || !isset($taskResults['result'])) {
        //logToFile("Предупреждение: Не удалось получить результаты задачи #{$task_id}");
        $taskResults = ['result' => []];
    }

    $results = $taskResults['result'];
    //logToFile(['results_count' => count($results)]);

    // 3. Обрабатываем результаты
    $fileIds = [];
    $textResult = '';

    if (!empty($results)) {
        $result = $results[0]; // Берем первый результат

        // Получаем файлы
        if (!empty($result['files']) && is_array($result['files'])) {
            $fileIds = $result['files']; // Исходные ID из результата
            //logToFile(['original_file_ids' => $fileIds]);
            
            // Попробуем получить реальные FILE_ID через комментарий
            if (!empty($result['commentId'])) {
                $commentInfo = callB24Api("task.commentitem.get", [
                    'TASKID' => $task_id,
                    'ITEMID' => $result['commentId']
                ], $access_token, $domain);
                
                //logToFile(['comment_request' => 'Запрашиваем комментарий', 'comment_id' => $result['commentId']]);
                
                if ($commentInfo && isset($commentInfo['result']['ATTACHED_OBJECTS'])) {
                    //logToFile(['attached_objects_found' => $commentInfo['result']['ATTACHED_OBJECTS']]);
                    
                    $realFileIds = [];
                    foreach ($commentInfo['result']['ATTACHED_OBJECTS'] as $attachedFile) {
                        if (isset($attachedFile['FILE_ID'])) {
                            $realFileIds[] = $attachedFile['FILE_ID']; // Добавляем реальный FILE_ID
                        }
                    }
                    
                    if (!empty($realFileIds)) {
                        $fileIds = $realFileIds; // Заменяем на реальные ID
                        //logToFile(['real_file_ids_found' => $realFileIds, 'replaced_from' => $result['files']]);
                    } else {
                        //logToFile(['no_file_ids_in_attached_objects' => 'FILE_ID не найден в ATTACHED_OBJECTS']);
                    }
                } else {
                    logToFile(['comment_debug' => [
                        'comment_info_exists' => isset($commentInfo),
                        'has_result' => isset($commentInfo['result']),
                        'has_attached_objects' => isset($commentInfo['result']['ATTACHED_OBJECTS']),
                        'comment_response' => $commentInfo
                    ]]);
                }
            } else {
                //logToFile(['no_comment_id' => 'commentId отсутствует в результате задачи']);
            }
            
            //logToFile(['final_file_ids' => $fileIds]);
        }

        // Получаем текстовый результат
        if (!empty($result['text'])) {
            $textResult = $result['text'];
            //logToFile(['text_result' => $textResult]);
        }
    }

    // 4. Записываем файлы в сущность, если есть файлы
    $entityUpdateSuccess = false;
    if (!empty($fileIds)) {
        $entityUpdateSuccess = updateEntity(
            $entity_type,
            $entity_id,
            $field_code,
            $fileIds,
            $smart_process_id,
            $access_token,
            $domain
        );
    } else {
        //logToFile('Нет файлов для записи в сущность');
        $entityUpdateSuccess = true; // Считаем успешным, если нет файлов
    }

    // 5. Формируем возвращаемые значения
    $returnValues = [
        'success' => $entityUpdateSuccess,
        'files_count' => count($fileIds),
        'files_ids' => implode(',', $fileIds),
        'text_result' => $textResult,
        'message' => $entityUpdateSuccess ? 
            'Файлы успешно записаны в сущность' : 
            'Ошибка при записи файлов в сущность'
    ];

    // 6. Отправляем результат в бизнес-процесс
    $bizprocResult = sendBizprocResult($eventToken, $returnValues, $access_token, $domain);

    // 7. Формируем ответ
    $response = [
        'success' => $entityUpdateSuccess,
        'message' => $returnValues['message'],
        'files_count' => count($fileIds),
        'files_ids' => $fileIds,
        'text_result' => $textResult,
        'entity_updated' => $entityUpdateSuccess
    ];

    //logToFile(['success' => $response]);
    echo json_encode($response);

} catch (Exception $e) {
    // Обработка исключений
    $errorMessage = 'Внутренняя ошибка сервера: ' . $e->getMessage();
    
    $returnValues = [
        'success' => false,
        'message' => $errorMessage,
        'files_count' => 0,
        'files_ids' => '',
        'text_result' => ''
    ];

    sendBizprocResult($eventToken, $returnValues, $access_token, $domain);

    //logToFile(['exception' => $errorMessage, 'trace' => $e->getTraceAsString()]);
    http_response_code(500);
    echo json_encode(['error' => $errorMessage]);
}
?>