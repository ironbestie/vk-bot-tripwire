<?php
// ===== НАСТРОЙКИ =====
$token = 'ВСТАВЬТЕ_СЮДА_ВАШ_VK_TOKEN';
$group_id = ВСТАВЬТЕ_СЮДА_VK_GROUP_ID;
$confirmation_code = 'ВСТАВЬТЕ_СЮДА_КОД_ОТ_VK';

// Ссылка на гайд (замените на свою)
$guide_link = 'https://vk.com/docВАШ_ID_ДОКУМЕНТА';
// ======================

// Получаем данные от VK
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Логируем все события
file_put_contents('vk_log.txt', date('Y-m-d H:i:s') . ' | ' . $input . "\n", FILE_APPEND);

// Подтверждение сервера
if (isset($data['type']) && $data['type'] === 'confirmation') {
    echo $confirmation_code;
    exit;
}

// Обработка событий
if (isset($data['type'])) {
    $type = $data['type'];
    $obj = isset($data['object']) ? $data['object'] : [];

    // СООБЩЕНИЕ В ЛИЧКУ
    if ($type === 'message_new') {
        $msg = isset($obj['message']) ? $obj['message'] : $obj;
        $user_id = isset($msg['from_id']) ? $msg['from_id'] : 0;
        $text = isset($msg['text']) ? strtolower(trim($msg['text'])) : '';

        if ($user_id > 0) {
            if (in_array($text, ['привет', 'начать', 'старт', '/start'])) {
                vkSend($user_id, "Отлично! 🙌\n\nОтветь на 3 вопроса:\n\n1️⃣ Как тебя зовут?\n2️⃣ Чем занимаешься?\n3️⃣ Какой результат хочешь?");
            } else {
                vkSend($user_id, "Спасибо! Специалист свяжется с тобой в течение часа 👍");
            }
        }
    }

    // КОММЕНТАРИЙ К ЗАПИСИ
    elseif ($type === 'wall_reply_new') {
        processComment($obj, 'записи');
    }

    // КОММЕНТАРИЙ К КЛИПУ/ВИДЕО
    elseif ($type === 'video_comment_new') {
        processComment($obj, 'Клипу');
    }

    // НАЖАТИЕ НА КНОПКУ
    elseif ($type === 'message_event') {
        processButtonClick($obj);
    }
}

// Обработка комментария (С ПРОВЕРКОЙ ПОДПИСКИ)
function processComment($data, $source) {
    global $token, $group_id, $guide_link;

    $user_id = isset($data['from_id']) ? $data['from_id'] : 0;
    $text = isset($data['text']) ? strtolower($data['text']) : '';

    // Проверяем кодовое слово
    if (strpos($text, 'гайд') !== false || strpos($text, 'разбор') !== false || strpos($text, 'хочу') !== false) {
        
        // Получаем имя пользователя
        $info = json_decode(file_get_contents(
            "https://api.vk.com/method/users.get?user_ids={$user_id}&access_token={$token}&v=5.199"
        ), true);
        $name = isset($info['response'][0]['first_name']) ? $info['response'][0]['first_name'] : 'друг';

        // СРАЗУ ПРОВЕРЯЕМ ПОДПИСКУ
        $check_url = "https://api.vk.com/method/groups.isMember" .
                     "?group_id={$group_id}" .
                     "&user_id={$user_id}" .
                     "&access_token={$token}" .
                     "&v=5.199";
        
        $check_result = json_decode(file_get_contents($check_url), true);
        $is_member = isset($check_result['response']) && $check_result['response'] == 1;

        file_put_contents('vk_log.txt', date('Y-m-d H:i:s') . " | КОММЕНТАРИЙ: user_id={$user_id}, подписан=" . ($is_member ? 'ДА' : 'НЕТ') . "\n", FILE_APPEND);

        if ($is_member) {
            // ✅ УЖЕ ПОДПИСАН — сразу отправляем гайд
            vkSend($user_id, 
                "Привет, {$name}! 👋\n\n" .
                "Вижу, ты уже подписан(а) на наше сообщество — спасибо! 🙌\n\n" .
                "🎁 Держи свой гайд по продажам в соц.сетях:\n" .
                "{$guide_link}\n\n" .
                "Сохрани сообщение, чтобы не потерять! 📌"
            );
        } else {
            // ❌ НЕ ПОДПИСАН — просим подписаться и показываем кнопку
            $keyboard = json_encode([
                "inline" => true,
                "one_time" => false,
                "buttons" => [
                    [
                        [
                            "action" => [
                                "type" => "callback",
                                "label" => "✅ Подписался(ась)",
                                "payload" => json_encode(["button" => "check_subscription"])
                            ],
                            "color" => "positive"
                        ]
                    ]
                ]
            ], JSON_UNESCAPED_UNICODE);

            vkSendWithKeyboard($user_id, 
                "Привет, {$name}! 👋\n\n" .
                "Вижу, ты заинтересовался гайдом по продажам в соц.сетях!\n\n" .
                "Чтобы получить гайд, подпишись на наше сообщество:\n" .
                "👉 https://vk.com/club{$group_id}\n\n" .
                "После подписки нажми кнопку ниже 👇",
                $keyboard
            );
        }
    }
}

// Обработка нажатия кнопки (на случай, если пользователь нажмёт её)
function processButtonClick($data) {
    global $token, $group_id, $guide_link;

    $user_id = isset($data['user_id']) ? $data['user_id'] : 0;
    $peer_id = isset($data['peer_id']) ? $data['peer_id'] : 0;
    $event_id = isset($data['event_id']) ? $data['event_id'] : '';
    
    $payload_raw = isset($data['payload']) ? $data['payload'] : [];
    if (is_string($payload_raw)) {
        $payload = json_decode($payload_raw, true);
    } else {
        $payload = $payload_raw;
    }

    if (isset($payload['button']) && $payload['button'] === 'check_subscription') {
        // Проверяем подписку
        $check_url = "https://api.vk.com/method/groups.isMember" .
                     "?group_id={$group_id}" .
                     "&user_id={$user_id}" .
                     "&access_token={$token}" .
                     "&v=5.199";
        
        $check_result = json_decode(file_get_contents($check_url), true);
        $is_member = isset($check_result['response']) && $check_result['response'] == 1;

        if ($is_member) {
            $event_data = json_encode([
                "type" => "show_snackbar",
                "text" => "🎉 Отправляю гайд в личку..."
            ], JSON_UNESCAPED_UNICODE);

            sendCallbackAnswer($event_id, $user_id, $peer_id, $event_data);
            vkSend($user_id, "🎁 Держи свой гайд по продажам в соц.сетях:\n\n{$guide_link}\n\nСохрани сообщение, чтобы не потерять! 📌");
        } else {
            $event_data = json_encode([
                "type" => "show_snackbar",
                "text" => "❌ Сначала подпишись на сообщество!"
            ], JSON_UNESCAPED_UNICODE);

            sendCallbackAnswer($event_id, $user_id, $peer_id, $event_data);
        }
    }
}

// Отправка ответа на callback-событие
function sendCallbackAnswer($event_id, $user_id, $peer_id, $event_data) {
    global $token;

    $params = http_build_query([
        'event_id' => $event_id,
        'user_id' => $user_id,
        'peer_id' => $peer_id,
        'event_data' => $event_data,
        'access_token' => $token,
        'v' => '5.199'
    ]);

    $ch = curl_init('https://api.vk.com/method/messages.sendMessageEventAnswer');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// Отправка сообщения с клавиатурой
function vkSendWithKeyboard($user_id, $text, $keyboard) {
    global $token;

    $params = http_build_query([
        'user_id' => $user_id,
        'message' => $text,
        'keyboard' => $keyboard,
        'random_id' => rand(1, 999999999),
        'access_token' => $token,
        'v' => '5.199'
    ]);

    $ch = curl_init('https://api.vk.com/method/messages.send');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

// Отправка простого сообщения
function vkSend($user_id, $text) {
    global $token;

    $params = http_build_query([
        'user_id' => $user_id,
        'message' => $text,
        'random_id' => rand(1, 999999999),
        'access_token' => $token,
        'v' => '5.199'
    ]);

    $ch = curl_init('https://api.vk.com/method/messages.send');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

echo 'ok';
?>
