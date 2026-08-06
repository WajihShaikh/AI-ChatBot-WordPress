# AI Chat Support for WordPress

AI Chat Support adds a lead-capture chatbot to WordPress with persistent conversation history, exact replies, voice input, an external embed, and a multi-provider AI layer.

## Version 2.1 highlights

- Provider-based architecture with OpenAI and Google Gemini adapters.
- OpenAI Responses API integration.
- Google Gemini `generateContent` integration.
- Dynamic model discovery from each provider, plus a curated fallback catalog.
- Current fallback choices including GPT-5.6, GPT-5.4, and current Gemini 3.x models.
- Separate API key, model, connection status, Test Connection, and Refresh Models controls for each provider.
- Masked API key fields and non-autoloaded protected storage.
- Authenticated encryption with Sodium or OpenSSL when available.
- Automatic migration of version 1.x OpenAI and Gemini keys.
- Modern tabbed settings UI with accessible keyboard navigation.
- Rate limiting for chat messages and session creation.
- Safer visitor-facing errors that do not expose provider details or API keys.
- Hardened REST, AJAX, nonce, capability, validation, and session checks.
- Automatic language detection that replies in the visitor’s current language.
- Browser-locale fallback for short messages and language-aware voice input.

## Automatic visitor language

Enable **Automatically match the visitor’s language** under **AI Chats → Settings → Assistant**. The assistant detects the language of the latest visitor message and replies in the same language. When a message is too short or ambiguous, the browser locale is used as a fallback. Visitors can switch languages naturally during the same conversation.

The configured welcome message appears before the visitor sends a message, so administrators serving multiple languages should use a neutral or multilingual welcome message.

## Existing features retained

- Native WordPress frontend chat widget.
- External one-line JavaScript embed.
- Name, email, and optional phone lead capture.
- Persistent chat sessions and transcript history.
- Admin transcript viewer and deletion controls.
- Exact question-and-answer replies.
- Website context/system instructions.
- Configurable welcome badge title, subtitle, icon, and welcome message.
- Voice input, emoji picker, typing indicators, and minimized chat state.

## Requirements

- WordPress 6.0 or newer.
- PHP 7.4 or newer.
- HTTPS is recommended and required by browsers for voice input outside localhost.
- Sodium or OpenSSL is strongly recommended for encrypted API key storage.

## Installation or upgrade

1. Back up the WordPress files and database.
2. Upload the ZIP from **Plugins → Add New → Upload Plugin**.
3. When WordPress detects the existing plugin folder, choose **Replace current with uploaded**.
4. Activate the plugin.
5. Open **AI Chats → Settings**.
6. Save or confirm the provider configuration.
7. Click **Refresh Models**, choose a model, and click **Test Connection**.
8. Save Settings.

Do not activate the old and upgraded copies at the same time.

## Provider configuration

### OpenAI

- Enter an **OpenAI API key**.
- Click **Refresh Models** to load compatible text models available to the OpenAI project.
- Choose a model from the dropdown.
- Click **Test Connection**.

The built-in catalog includes GPT-5.6 and GPT-5.4 family choices as a fallback. The API-provided list is preferred because model access can vary by project and over time.

### Google Gemini

- Enter a **Google Gemini API key**.
- Click **Refresh Models** to load compatible Gemini text models available to the key.
- Choose a model from the dropdown.
- Click **Test Connection**.

The built-in catalog includes current Gemini 3.x choices and moving `-latest` aliases as a fallback.

## API key handling

Provider API keys:

- Are used only in server-side PHP requests.
- Are never localized into frontend JavaScript.
- Are never returned by an AJAX or REST response.
- Are stored in non-autoloaded WordPress options.
- Are encrypted with Sodium authenticated encryption when available.
- Otherwise use OpenSSL AES-256-GCM when available.
- Fall back to base64 compatibility storage only when neither crypto extension exists; the Security screen displays a warning in that case.
- Are shown only as a last-four-character mask after saving.
- Are redacted from provider error messages.

Encryption is derived from WordPress salts. If the salts in `wp-config.php` are changed, saved provider keys may need to be entered again.

The external embed contains a revocable **widget access key** in its script URL. This is intentionally visible to the external page and is not an AI-provider API key. Server-side rate limiting protects the underlying AI requests.

## Admin areas

- **AI Chats**: dashboard, lead details, transcripts, and deletion.
- **Settings**: providers, assistant instructions, widget appearance, and security controls.
- **Exact Replies**: deterministic answers that run before an AI request.
- **Embed Code**: script tag and revocable widget access key.

## Database tables

- `{prefix}ai_chats`: lead/session metadata.
- `{prefix}ai_chat_messages`: conversation messages.
- `{prefix}ai_chat_exact_replies`: exact question-and-answer rules.

The upgrade preserves these existing tables and records.

## Extending providers

Implement `AI_Chat_Provider_Interface`, extend `AI_Chat_Provider_Base` where useful, and register the adapter through the `ai_chat_providers` filter. The settings UI automatically renders registered providers that follow the interface.

## Verification performed

- PHP syntax checks for all plugin PHP files.
- JavaScript syntax checks for all plugin JavaScript files.
- CSS brace-balance checks.
- OpenAI request/response smoke test with a mocked WordPress HTTP layer.
- Gemini request/response smoke test with a mocked WordPress HTTP layer.
- API key encryption, decryption, masking, and legacy migration smoke tests.
- Static checks for raw superglobal use, frontend API-key exposure, legacy alerts, and obsolete provider endpoints.

A live WordPress runtime test and live provider billing/account test still require a staging site and valid API keys.

## License

GPL v2 or later.
