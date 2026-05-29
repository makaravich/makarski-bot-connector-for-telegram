<?php

namespace TGBot;

class TGBotCommands {
    public function start($bot): void {
        $chat_id = $bot->chat_id;
        $user = ProcessMessages::get_user_by_chat_id($chat_id);
        $bot_map = $bot->get_map();
        $user_data = $bot_map->request_respond->message->from ?? null;

        $bot->send_message(__('Start command','tgbot'));
    }
}