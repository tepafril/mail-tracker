# Master Plan: Outlook-to-CRM Email Tracking Integration

## 1. Project Overview & Objectives
The goal of this project is to build an integration between Microsoft Outlook/365 and our internal CRM system to capture, match, and log client communication as CRM activities.

### Core Objectives
*   **Data Integrity**: Automatically link incoming and outgoing client emails to the correct CRM contact record.
*   **Efficiency**: Eliminate manual copy-pasting of email interactions for sales and support reps.
*   **Security & Compliance**: Securely handle email payloads following corporate data privacy protocols.

---

## 2. Architectural Decision Matrix
Depending on requirements, we will implement one of two primary architectural patterns, or a hybrid version combining both.

### Option A: User-Driven Outlook Sidebar Add-In (Frontend)
*   **Tech Stack**: HTML5, TypeScript/JavaScript, React (optional), `Office.js` SDK.
*   **Workflow**: User opens an email -> Sidebar loads -> User clicks "Log to CRM" -> Frontend hits CRM API.
*   **Best For**: High user control, filtering out personal/internal emails, and displaying live CRM data inside Outlook.

### Option B: Zero-Touch Sync Engine (Backend)
*   **Tech Stack**: Node.js/Python/.NET, Azure Event Hubs/Webhooks, Microsoft Graph API.
*   **Workflow**: Email hits Exchange -> Microsoft sends webhook to CRM listener -> Backend parses and logs email.
*   **Best For**: 100% automated background tracking with zero user friction.

---

## 3. High-Level System Architecture (Option B Workflow)
[External Client] ---> (Sends Email) ---> [Exchange Online / M365]|(Webhook Notification)v[CRM Database]  <--- (Log Activity) <--- [CRM Sync Engine Listener]


---

## 4. Phase-by-Phase Execution Plan

### Phase 1: Environment & Authentication Setup (Week 1)
*   [ ] Create a developer Sandbox tenant in Microsoft 365.
*   [ ] Register a new Application in the **Azure Portal (App Registrations)**.
*   [ ] Configure Application Permissions based on chosen path:
    *   *For Add-in*: Configure OAuth2/OIDC delegated permissions.
    *   *For Graph API*: Grant `Mail.Read` or `Mail.ReadBasic.All` application permissions.
*   [ ] Set up Client Secrets and generate a secure tenant ID.

### Phase 2: Core Core Engine & CRM API Development (Week 2)
*   [ ] Create the CRM endpoint `POST /api/v1/activities/email-log`.
*   [ ] Develop lookup logic: Match incoming email strings (`from`/`to`) against CRM `contacts.email` table.
*   [ ] Build deduplication logic: Hash the Exchange `InternetMessageId` to prevent logging duplicate threads.
*   [ ] Standardize activity schema: Subject, Body (sanitized HTML), Timestamp, Attachments (optional), and Participant IDs.

### Phase 3: Integration Component Build (Weeks 3-4)

#### If choosing Option A (Add-in):
*   [ ] Initialize the project scaffold using `yo office` (Office Add-in generator).
*   [ ] Configure the `manifest.json` / `manifest.xml` targeting `MailRead` and `MailCompose` surfaces.
*   [ ] Write JavaScript to extract metadata using `Office.context.mailbox.item`.
*   [ ] Implement **Event-Based Activation** (`OnMessageSend`) to catch outbound mail.

#### If choosing Option B (Graph API):
*   [ ] Build a public-facing HTTPS webhook endpoint listener on your backend.
*   [ ] Write a script to create a **Graph Subscription** (`/subscriptions`) on targeted employee mailboxes.
*   [ ] Implement lifecycle management to automatically renew the Graph subscription every 4230 minutes (3 days).
*   [ ] Build a parsing pipeline to process the notification delta payloads asynchronously.

### Phase 4: Security, Privacy & Filtering (Week 5)
*   [ ] **Domain Blacklist**: Write an exclusion filter to immediately drop internal emails (e.g., `*@yourcompany.com`).
*   [ ] **Data Sanitization**: Strip dangerous scripts or tracking pixels from email bodies before storing them in the CRM database.
*   [ ] **Encryption**: Secure all database payloads containing PII (Personally Identifiable Information) at rest.

### Phase 5: Testing & Deployment (Week 6)
*   [ ] **Unit Testing**: Mock Graph API payloads and test edge cases (empty subjects, massive attachments).
*   [ ] **UAT (User Acceptance Testing)**: Sideload the add-in or enable the webhook tracking for a small pilot group of 5 users.
*   [ ] **Production Launch**:
    *   *Add-in*: Deploy centrally via the Microsoft 365 Admin Center -> Integrated Apps.
    *   *Graph API*: Admin consents to corporate-wide Azure App deployment.

---

## 5. Key Technical Risks & Mitigations

| Risk | Impact | Mitigation Strategy |
| :--- | :--- | :--- |
| **Microsoft Graph API Throttling** | High | Implement exponential backoff retry logic using queue management (like RabbitMQ or AWS SQS). |
| **Storage Bloat from Attachments** | High | Do not store heavy files directly in the database. Stream attachments directly to secure cloud storage (S3/Azure Blobs) and link them. |
| **Privacy Concerns** | Critical | Implement an "Opt-Out" configuration or a strictly defined inclusion list of synced employee boxes. |

---

## 6. Maintenance & Monitoring
*   **Health Checks**: Set up alerts for failed webhook deliveries (HTTP 5xx responses).
*   **Token Expiry**: Automate token rotation for Azure app credentials.
*   **Audit Logging**: Maintain a system log recording every attempt to track an email, noting success or failure codes.