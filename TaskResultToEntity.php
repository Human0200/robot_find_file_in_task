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

    // 2. Получаем результаты задачи
    $taskResults = callB24Api("tasks.task.result.list", ['taskId' => $task_id], $access_token, $domain);
    if (!$taskResults || !isset($taskResults['result'])) {
        logToFile("Предупреждение: Не удалось получить результаты задачи #{$task_id}");
        $taskResults = ['result' => []];
    }

    $results = $taskResults['result'];
    logToFile(['results_count' => count($results)]);

    // 3. Обрабатываем результаты
    $fileIds = [];
    $textResult = '';

    if (!empty($results)) {
        $result = $results[0]; // Берем первый результат

        // Получаем файлы
        if (!empty($result['files']) && is_array($result['files'])) {
            $fileIds = $result['files']; // Исходные ID из результата (например, [4925])
            logToFile(['original_file_ids' => $fileIds]);
            
            // Попробуем получить реальные FILE_ID через комментарий
            if (!empty($result['commentId'])) {
                $commentInfo = callB24Api("task.commentitem.get", [
                    'TASKID' => $task_id,
                    'ITEMID' => $result['commentId']
                ], $access_token, $domain);
                
                logToFile(['comment_request' => 'Запрашиваем комментарий', 'comment_id' => $result['commentId']]);
                
                if ($commentInfo && isset($commentInfo['result']['ATTACHED_OBJECTS'])) {
                    logToFile(['attached_objects_found' => $commentInfo['result']['ATTACHED_OBJECTS']]);
                    
                    $realFileIds = [];
                    foreach ($commentInfo['result']['ATTACHED_OBJECTS'] as $attachedFile) {
                        if (isset($attachedFile['FILE_ID'])) {
                            $realFileIds[] = $attachedFile['FILE_ID']; // Добавляем реальный FILE_ID
                        }
                    }
                    
                    if (!empty($realFileIds)) {
                        $fileIds = $realFileIds; // Заменяем на реальные ID
                        logToFile(['real_file_ids_found' => $realFileIds, 'replaced_from' => $result['files']]);
                    } else {
                        logToFile(['no_file_ids_in_attached_objects' => 'FILE_ID не найден в ATTACHED_OBJECTS']);
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
                logToFile(['no_comment_id' => 'commentId отсутствует в результате задачи']);
            }
            
            logToFile(['final_file_ids' => $fileIds]);
        }

        // Получаем текстовый результат
        if (!empty($result['text'])) {
            $textResult = $result['text'];
            logToFile(['text_result' => $textResult]);
        }
    }

    // Если нет результатов, используем описание задачи
    if (empty($fileIds) && empty($textResult)) {
        $textResult = !empty($taskData['DESCRIPTION']) ? $taskData['DESCRIPTION'] : '';
        logToFile(['fallback_to_description' => $textResult]);
    }

    // 4. Формируем возвращаемые значения
    $returnValues = [
        'files' => implode(',', $fileIds), // Массив ID файлов через запятую
        'text' => $textResult
    ];

    // 5. Отправляем результат в бизнес-процесс
    $bizprocResult = sendBizprocResult($eventToken, $returnValues, $access_token, $domain);

    // 6. Формируем ответ
    $response = [
        'success' => true,
        'message' => 'Результат задачи успешно получен',
        'files' => $fileIds,
        'text' => $textResult
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