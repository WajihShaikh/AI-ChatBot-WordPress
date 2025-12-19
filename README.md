# AI Chat Support WordPress Plugin 🤖

## Overview
AI Chat Support is a premium-quality, lightweight WordPress plugin that adds an intelligent, conversational agent to your website. It supports both OpenAI (GPT-4/GPT-4o) and Google Gemini (1.5/2.0 Flash), allowing you to choose the best AI model for your specific needs.

Unlike simple API wrappers, this plugin features persistent chat history, lead generation (user details capture), voice input, and a dedicated admin dashboard to review conversation transcripts.

## ✨ Key Features
- **Multi-Model Support**: Connects with OpenAI (GPT-3.5, GPT-4, GPT-4o) or Google Gemini (Flash 1.5, Pro 1.5, Flash 2.0).
- **Custom Persona & Context**: Define exactly who the AI is (e.g., "You are a support agent for Mario's Pizza") and set business rules via the settings panel.
- **Lead Capture**: Requires users to provide Name, Email, and Purpose before chatting, helping you build your CRM/lead database.
- **Persistent History**: Chat history is saved in your WordPress database. Users can refresh the page, and their conversation remains intact.
- **Rich User Interface**:
  - 🎙️ Voice Input: Speech-to-text integration for hands-free typing.
  - 😀 Emoji Picker: Native-style emoji support.
  - 🌗 Minimizable Window: Users can collapse the chat without losing the session.
  - ✨ Typing Effects: Smooth text streaming and typing indicators.
- **Admin Dashboard**: View full chat transcripts, user details, and delete old logs.
- **Customizable Widget**: Change the welcome badge title, subtitle, and icon to match your brand.

## 🚀 Installation
1. **Download**: Clone this repository or download the ZIP file.
2. **Upload to WordPress**:
   - Go to your WordPress Admin Dashboard.
   - Navigate to Plugins > Add New > Upload Plugin.
   - Select the ZIP file and click Install Now.
3. **Activate**: Click Activate Plugin.
   - *Note*: Upon activation, the plugin automatically creates two custom tables in your database (`wp_ai_chats` and `wp_ai_chat_messages`) to store history.

## ⚙️ Configuration
Once activated, go to **AI Chats > Settings** in your WordPress sidebar.

### 1. API Configuration
You must choose a provider and enter an API Key.
- **OpenAI**:
  - Select OpenAI (ChatGPT).
  - Enter your OpenAI API Key.
  - Select a Model (Recommend: gpt-4o or gpt-3.5-turbo for speed).
- **Google Gemini**:
  - Select Google Gemini.
  - Enter your Gemini API Key.
  - Select a Model (Recommend: gemini-1.5-flash for speed and low cost).

### 2. AI Personality (Context)
In the **Website Context / Instructions** box, define the AI's behavior.  
*Example*:  
"You are a helpful customer support agent for [Company Name]. We sell organic coffee. Our hours are 9 AM - 5 PM. Do not answer questions about math or coding. Be polite and concise."

### 3. Widget Appearance
Customize how the chat bubble looks on the frontend:
- **Badge Title**: e.g., "Need Help?"
- **Badge Subtitle**: e.g., "Chat with our AI."
- **Badge Icon**: Paste any emoji (e.g., 🤖, 💬, 👋).

## 🖥️ Screenshots
- Chat Widget
- Admin History
- Settings Panel

## 🛠️ Technical Details
### Database Structure
The plugin creates two tables to manage persistent state:
- `{prefix}ai_chats`: Stores session IDs, user info (Name/Email), and chat purpose.
- `{prefix}ai_chat_messages`: Stores the actual conversation logs (User vs. Assistant roles).

### Files Overview
- `ai-chat-support.php`: Main plugin file, PHP logic, database creation, and API handling.
- `script.js`: Frontend logic, AJAX handling, Session storage, Voice recognition, and UI manipulation.
- `admin.js`: Logic for the Admin Dashboard (viewing/deleting chats).
- `style.css`: Modern, responsive styling (Glassmorphism effects, gradients, animations).

## 🤝 Contributing
Contributions are welcome!  
1. Fork the repository.
2. Create a new branch (`git checkout -b feature/NewFeature`).
3. Commit your changes.
4. Push to the branch and open a Pull Request.

## 📝 License
This project is licensed under the GPL v2 or later.

## 👤 Author
**Wajih Shaikh**  
Company: GoAccelovate