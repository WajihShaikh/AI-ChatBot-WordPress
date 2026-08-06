# AI Chat Support 2.0 — Plugin Audit and Upgrade Report

## 1. Original architecture reviewed

The original plugin used seven top-level files and placed most backend behavior in one PHP class of roughly 1,100 lines. That class handled activation, database tables, admin screens, frontend markup, AJAX, REST, exact replies, embed delivery, OpenAI requests, and Gemini requests.

The existing workflows were mapped before changes:

1. A visitor opens the native or external widget.
2. The visitor submits name, email, and optional phone/purpose.
3. WordPress creates a session in `ai_chats`.
4. Messages are stored in `ai_chat_messages`.
5. An exact reply is checked first.
6. Otherwise the selected AI provider receives recent history and website instructions.
7. The assistant response is stored and displayed.
8. Administrators can review/delete conversations and manage exact replies.

## 2. Existing functionality preserved

- Native frontend widget.
- External embed widget.
- Lead capture.
- Persistent sessions/history.
- Voice input.
- Emoji picker.
- Typing animation and indicator.
- Welcome badge customization.
- Assistant context/instructions.
- Exact replies.
- Admin transcript viewer.
- Chat deletion.
- Existing database tables and records.

## 3. Key issues found in the original plugin

### Architecture

- Provider logic was embedded directly in the main plugin class.
- OpenAI and Gemini request logic was duplicated instead of sharing a provider contract.
- Adding another provider would require editing the central class and settings markup.
- Backend, admin UI, frontend rendering, database, and API logic were tightly coupled.

### API integration

- Models were hard-coded and stale.
- OpenAI used the older Chat Completions flow.
- Gemini fallbacks included retired Gemini 1.5/2.0 choices.
- No provider connection test existed.
- No model discovery existed.
- Provider failures could surface technical details to visitors.

### API key security

- Keys were stored as ordinary plaintext WordPress options.
- Saved keys were rendered back into password inputs in full.
- Legacy options could autoload on normal requests.
- No encryption layer existed.
- No key masking or removal workflow existed.
- No provider-specific connection state existed.

### Request security

- REST routes originally used unrestricted permission callbacks.
- Public message endpoints had no meaningful rate limiting.
- Session IDs were not consistently checked before use.
- Some AJAX values were read without complete unslash/sanitize handling.
- Admin chat actions needed stronger error/status handling.
- External embed access depended on a public token without sufficient server-side throttling.

### UI and UX

- Settings used a basic WordPress table layout.
- Provider configuration was difficult for non-technical administrators to understand.
- Model IDs had to come from static lists.
- No loading states existed for provider actions.
- Browser alerts were used for validation and admin failures.
- Empty, error, connection, and saved-key states were unclear.
- The close button duplicated minimize behavior.
- Several accessibility details were missing, including keyboard tab behavior and modal focus restoration.

### Maintenance

- Documentation described obsolete models.
- The plugin had no provider extension contract.
- There was no isolated way to test provider payloads or key migration behavior.

## 4. Upgrade implemented

### Provider architecture

Added:

- `AI_Chat_Provider_Interface`
- `AI_Chat_Provider_Base`
- `AI_Chat_Provider_OpenAI`
- `AI_Chat_Provider_Gemini`
- `AI_Chat_Provider_Manager`
- `ai_chat_providers` extension filter

Each provider now owns:

- Label and ID.
- Default/fallback models.
- Dynamic model discovery.
- Connection testing.
- Request creation.
- Response parsing.
- Provider-specific error conversion.

### OpenAI

- Uses the Responses API.
- Uses server-side Bearer authentication.
- Supports dynamic `/v1/models` discovery.
- Filters out audio, realtime, image, moderation, embedding, and unrelated models.
- Includes curated GPT-5.6, GPT-5.5, GPT-5.4, GPT-4.1, and GPT-4o-mini fallback records.
- Sends `store: false`.
- Parses both `output_text` and structured response output.

### Google Gemini

- Uses `models/{model}:generateContent`.
- Uses the official `x-goog-api-key` request header.
- Supports dynamic `/v1beta/models` discovery.
- Filters out image, live, audio, TTS, embedding, robotics, computer-use, and deep-research models.
- Includes curated current Gemini 3.x and moving-alias fallbacks.
- Sends multi-turn `contents`, a server-side `systemInstruction`, and `store: false`.
- Parses candidate content parts and reports safe finish information to administrators.

### Key storage

Added `AI_Chat_Key_Store`:

- Separate option per provider.
- Non-autoloaded options.
- Sodium secretbox authenticated encryption when available.
- OpenSSL AES-256-GCM fallback.
- Explicit warning when only the non-encrypted compatibility fallback is possible.
- Last-four-character masking.
- Blank-on-render key fields.
- Replace/remove workflows.
- Automatic migration from `ai_chat_openai_key` and `ai_chat_gemini_key`.
- Redaction of recognizable OpenAI/Gemini key formats in errors.

### Admin UI

- Modern SaaS-style dashboard and settings workspace.
- Provider cards with active-provider selection.
- Separate official API key terminology for OpenAI and Google Gemini.
- Model dropdown with name, ID, description, capability, and lifecycle/account status.
- Refresh Models action.
- Test Connection action.
- Connected, not tested, not configured, testing, and needs-attention states.
- Accessible inline notices and loading states.
- Tabbed sections for Providers, Assistant, Widget, and Security.
- Keyboard-accessible tabs.
- Masked secret fields with accessible reveal controls.
- Security method and abuse-protection summaries.
- Improved transcript modal focus behavior and inline admin notices.

### Frontend and embed

- Accessible inline lead-form validation.
- Email and international phone-format checks.
- Sending/loading states.
- Clear visitor-safe errors.
- Expired-session recovery.
- Correct close behavior that preserves the session.
- No provider API keys in scripts, localized data, REST payloads, or page markup.
- Server validation for session existence and message length.
- Rate limiting by IP and session.
- Separate session-creation burst limit.
- Existing exact-reply, voice, emoji, history, and typing behavior retained.

## 5. Security controls now present

- `manage_options` checks for provider settings and admin conversation actions.
- WordPress nonces for settings, provider tests, model refreshes, exact replies, embed-key rotation, frontend AJAX, and admin AJAX.
- Sanitization/validation of provider IDs, model IDs, keys, messages, contact fields, rate limits, and session IDs.
- Prepared SQL where user values are used.
- Escaping for admin/frontend output.
- Provider keys only in server-side HTTP headers.
- No request headers or keys in logs or visitor errors.
- Revocable external widget access key.
- Per-IP and per-session throttling.
- Message length limit.
- Valid-session checks.
- HTTPS/SSL verification for provider requests.

## 6. Compatibility and migration

- Existing database schema and chat data are retained.
- Existing exact replies are retained.
- Existing assistant and widget settings are retained.
- Legacy OpenAI and Gemini key options are migrated on activation/init.
- Legacy model options are read as fallbacks and then use the new per-provider model options.
- The external embed format remains a single script tag.

## 7. Verification completed

- All seven PHP files pass `php -l`.
- All four JavaScript files pass `node --check`.
- Admin and frontend CSS have balanced braces.
- Mocked OpenAI provider smoke test passed.
- Mocked Gemini provider smoke test passed.
- Key encryption/decryption smoke test passed.
- Key masking smoke test passed.
- Legacy key migration smoke test passed.
- Remaining direct superglobal references were reviewed and sanitized.
- Legacy browser `alert()` calls were removed.
- Obsolete Gemini Interactions/2.0 request code is absent.

## 8. Staging checks still required

The code has been statically and independently tested, but the following require the target environment:

1. Install/upgrade on a staging clone.
2. Test with the site's actual PHP extensions and WordPress salts.
3. Test live OpenAI and Gemini keys, account permissions, quotas, and selected models.
4. Confirm outbound HTTPS requests are not blocked by hosting/firewall rules.
5. Test theme/mobile CSS interaction.
6. Test the external embed from every intended domain.
7. Confirm voice input under HTTPS in supported browsers.
8. Monitor provider usage and tune the rate limit for real traffic.

## 9. Recommended release procedure

1. Back up files and database.
2. Deploy to staging.
3. Open AI Chats → Settings.
4. Confirm both migrated key states.
5. Refresh the selected provider's models.
6. Select a model and run Test Connection.
7. Send native and external test chats.
8. Verify transcript persistence and exact replies.
9. Deploy to production during a low-traffic window.
10. Monitor provider errors and API usage after release.
