<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChatbotLoginEmailTest extends TestCase
{
    public function test_chatbot_login_uses_email_not_full_name(): void
    {
        $html = $this->get(route('chatbot.login'))->assertOk()->getContent();

        $this->assertStringContainsString('>Email</', $html);
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('placeholder="name@example.com"', $html);
        $this->assertStringNotContainsString('name="full_name"', $html);
        $this->assertStringNotContainsString('>Full Name</', $html);
        $this->assertStringContainsString("Don't have an account?", $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.register')).'"', $html);
        $this->assertStringNotContainsString('href="'.e(route('register')).'"', $html);
    }
}
