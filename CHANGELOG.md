# Changelog

## 2.1.0

- Added automatic visitor-language detection and same-language AI responses.
- Added browser-locale fallback for short or ambiguous messages.
- Added automatic language switching when a visitor changes language mid-conversation.
- Updated native and external widget voice recognition to use the visitor’s browser language.
- Added an accessible Assistant settings toggle for multilingual response behavior.
- Preserved exact-reply behavior, with provider-assisted translation and safe deterministic fallback.

## 2.0.0

- Added provider adapter architecture and future-provider filter.
- Added current OpenAI Responses API integration.
- Added current Google Gemini generateContent integration.
- Added dynamic model discovery and curated fallback catalogs.
- Added GPT-5.6, GPT-5.4, and current Gemini 3.x model choices.
- Added per-provider API key, model, status, test, and refresh controls.
- Added encrypted, masked, non-autoloaded API key storage and legacy migration.
- Redesigned the admin dashboard and settings experience.
- Added inline notices, loading states, empty states, and accessibility improvements.
- Added IP/session message throttling and session-creation throttling.
- Hardened REST and AJAX validation, permissions, nonces, errors, and session checks.
- Prevented provider details and keys from reaching visitors.
- Improved native and external widget validation, loading, close behavior, and expired-session recovery.
- Preserved existing history, lead capture, exact replies, voice, emoji, and embed features.
