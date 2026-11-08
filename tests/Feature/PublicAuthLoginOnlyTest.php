<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicAuthLoginOnlyTest extends TestCase
{
    public function test_public_landing_exposes_login_and_hides_register(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $this->assertStringContainsString('Login', $html);
        $this->assertStringNotContainsString('href="'.e(route('register')).'"', $html);
        $this->assertStringNotContainsString('>Register</', $html);
        $this->assertStringNotContainsString('Create Account', $html);
        $this->assertStringNotContainsString('Sign Up', $html);
    }

    public function test_staff_login_does_not_offer_public_registration(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('Login to Your Account', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringNotContainsString('method="get"', $html);
        $this->assertStringNotContainsString('href="'.e(route('register')).'"', $html);
        $this->assertStringNotContainsString('Don’t have any account?', $html);
        $this->assertStringNotContainsString("Don't have an account?", $html);
        $this->assertStringNotContainsString('>Register</', $html);
        $this->assertDoesNotMatchRegularExpression('/id="email"[^>]*value="(?!")[^"]+/', $html);
        $this->assertStringContainsString('value=""', $html);
    }

    public function test_chatbot_landing_exposes_login_and_register(): void
    {
        $html = $this->get(route('chatbot.landing'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.e(route('chatbot.login')).'"', $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.register')).'"', $html);
        $this->assertStringContainsString('lml-chatbot-hero__btn--primary', $html);
        $this->assertStringContainsString('lml-chatbot-hero__btn--secondary', $html);
        $this->assertMatchesRegularExpression('/>\s*Login\s*</', $html);
        $this->assertMatchesRegularExpression('/>\s*Register\s*</', $html);
    }

    public function test_chatbot_login_links_to_resident_registration(): void
    {
        $html = $this->get(route('chatbot.login'))->assertOk()->getContent();

        $this->assertStringContainsString("Don't have an account?", $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.register')).'"', $html);
        $this->assertStringContainsString('lml-chatbot-login__register-link', $html);
        $this->assertStringNotContainsString('href="'.e(route('register')).'"', $html);
    }

    public function test_legacy_registration_routes_remain_reachable_but_staff_login_stays_unlinked(): void
    {
        $this->get(route('register'))->assertOk();
        $this->get(route('chatbot.register'))->assertOk();

        $login = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('register'), $login);
        $this->assertStringNotContainsString(route('chatbot.register'), $login);
    }
}
