# Client Document Chaser

## 1. Product

Client Document Chaser is a simple SaaS that helps businesses collect documents from their clients without manually chasing them by email.

### Core value proposition

> **Stop chasing clients for documents.**

A business creates a document request, sends the client a secure link, and the system tracks which documents have been received and automatically reminds the client about anything still missing.

The product should be extremely simple compared to full document-management or CRM platforms.

---

# 2. Problem

Many businesses repeatedly need clients to provide documents.

Examples:

* accountants;
* bookkeepers;
* tax preparers;
* insurance brokers;
* mortgage brokers;
* lawyers;
* consultants;
* property managers;
* other professional services businesses.

The current process is often:

1. Business emails the client requesting documents.
2. Client sends some documents.
3. Business checks what is missing.
4. Business sends another email.
5. Client forgets again.
6. Business sends another reminder.
7. Documents arrive through different channels.
8. Someone manually tracks what has been received.

This is repetitive, error-prone and time-consuming.

The product should centralize this process into one simple workflow.

---

# 3. Target Customer

Initial target:

* small accounting firms;
* bookkeepers;
* tax preparers;
* small professional-service businesses.

Initial geographic focus:

* United States;
* United Kingdom;
* Canada;
* Australia.

The application UI and marketing copy should initially be in English.

The architecture should not hard-code the product to accounting.

The document-request model should be generic enough to support other professional services later.

---

# 4. Core Workflow

The core workflow is:

Business:

```text
Create client
    ↓
Create document request
    ↓
Select required documents
    ↓
Send request
```

Client:

```text
Open secure link
    ↓
See requested documents
    ↓
Upload documents
    ↓
See what is still missing
```

System:

```text
Document uploaded
    ↓
Mark document as received
    ↓
Check remaining documents
    ↓
If documents are missing
    ↓
Send reminder automatically
```

---

# 5. MVP Goal

The MVP must answer one question:

> **Will businesses pay to stop manually chasing clients for documents?**

The MVP does not need to become a complete document-management platform.

Speed of validation is more important than feature breadth.

---

# 6. P0 Features

## 6.1 Landing Page

Create a simple marketing landing page.

It should communicate:

### Headline

The exact copy can be refined later, but the core message should communicate:

> Stop chasing clients for documents.

### Supporting message

Explain that users can:

* request documents;
* send one simple upload link;
* track what's missing;
* automatically remind clients.

### CTA

Primary CTA:

> Start collecting documents

The CTA should lead to account creation/onboarding.

### Landing page sections

Keep the page simple:

1. Hero
2. How it works
3. Main benefits
4. Simple example/workflow
5. Pricing placeholder or initial pricing
6. FAQ
7. CTA

Do not build a complex marketing website.

---

# 7. Authentication

Businesses need an account.

MVP authentication should support:

* email;
* password;
* login;
* logout;
* password reset.

Clients do NOT need accounts.

The client experience must work through a secure, unguessable request link.

---

# 8. Business Dashboard

After authentication, the business sees a simple dashboard.

It should provide:

* total active requests;
* requests waiting for documents;
* completed requests;
* recent activity.

Do not build a complex analytics dashboard.

---

# 9. Client Management

Business users can:

* create a client;
* view clients;
* view client details;
* edit client information;
* archive a client.

Minimum client fields:

* name;
* email.

Do not collect unnecessary personal information.

---

# 10. Document Requests

A business can create a document request for a client.

A request contains:

* client;
* requested documents;
* optional message;
* optional due date;
* reminder configuration;
* status.

Example:

```text
Client:
John Smith

Email:
john@example.com

Documents:

[ ] Bank statement
[ ] Invoice
[ ] Identification
[ ] Contract

Due date:
September 15

Message:
Please upload the requested documents using the link below.
```

---

# 11. Document Types

For MVP, document types can simply be text labels.

Examples:

* Bank statement
* Invoice
* Identification
* Contract
* Tax document
* Receipt
* Other

The business should be able to add requested document items to a request.

Do not build a complex document-type management system yet.

---

# 12. Client Upload Portal

Clients access a request through a secure link.

No client account is required.

The page should show:

* business name;
* request message;
* requested documents;
* received/missing status;
* upload controls;
* due date if configured.

Example:

```text
Documents requested

✓ Bank statement
✓ Invoice
○ Identification
○ Contract

2 of 4 documents received
```

The client should clearly understand what is still missing.

---

# 13. File Upload

Clients can upload documents for each requested item.

Initial supported formats:

* PDF;
* JPG/JPEG;
* PNG;
* DOCX;
* XLSX.

Legacy binary Office formats (DOC, XLS) are intentionally excluded from the MVP: their real-world files are frequently sniffed by server-side content detection as a generic container type rather than the specific Word/Excel type, which would force accepting a broad "any OLE2 compound file" allowance to support them. DOCX/XLSX (the modern, ZIP-based formats) detect precisely and cover current business use.

Initial maximum file size:

**10 MB per file.**

This value must be configurable.

The server must validate:

* extension;
* actual MIME type;
* file contents;
* file size.

Never trust the client-provided filename or MIME type.

Files must use randomized storage names.

Original filenames may be stored as metadata only when necessary.

---

# 14. File Security

Uploaded files contain potentially sensitive business/client information.

Requirements:

* files must not be publicly accessible;
* never expose raw filesystem paths;
* never construct paths directly from user-controlled filenames;
* use randomized identifiers for storage;
* authorize every download;
* prevent users from accessing another tenant's files;
* validate uploaded file types;
* enforce file-size limits;
* protect upload endpoints with rate limits;
* do not execute uploaded files;
* do not log file contents.

Storage should be private.

---

# 15. Request Link Security

Client links must use cryptographically random, unguessable tokens.

Do not use:

* sequential IDs;
* client IDs;
* email addresses;
* predictable hashes.

The request token is effectively a credential and must be treated as sensitive.

A client who possesses the link can access that specific request.

The token must only grant access to that request and its associated uploads.

---

# 16. Email

The system must send email notifications.

Initial emails:

### Request email

Sent when the business creates/sends a request.

Contains:

* business name;
* client name;
* requested documents;
* secure upload link;
* due date when applicable.

### Reminder email

Sent automatically while required documents are still missing.

Contains:

* missing documents;
* secure upload link.

### Completion email

Optional for P0, but the business should be able to know when all requested documents have been received.

Email sending must happen asynchronously through the queue.

Do not block web requests while sending email.

---

# 17. Reminders

The system should automatically remind clients when documents are missing.

Initial MVP behavior:

* configurable reminder interval;
* reminders stop automatically when all documents are received;
* reminders stop when the request is closed or expired.

Do not build complex automation rules.

A simple reminder schedule is enough.

Example:

```text
Request created
    ↓
Client receives email
    ↓
2 days later
    ↓
If documents missing → reminder
    ↓
2 days later
    ↓
If documents missing → reminder
    ↓
All documents received
    ↓
Stop reminders
```

---

# 18. Request Status

A request can have statuses such as:

* Draft
* Sent
* In Progress
* Completed
* Expired
* Archived

The exact internal representation is an implementation detail.

The business must be able to clearly see whether a request is:

* waiting for documents;
* completed;
* expired.

---

# 19. Due Dates and Expiration

A request may have an optional due date.

If a due date exists:

* show it to the client;
* show it to the business;
* reminders can reference it.

Expired requests should no longer accept uploads unless explicitly reopened.

Do not delete uploaded files immediately when a request expires.

Retention policy should be configurable.

---

# 20. Business/User Isolation

The application must be multi-tenant.

A business user must only be able to access:

* their own clients;
* their own requests;
* their own documents;
* their own activity.

Never rely only on frontend filtering for authorization.

Every server-side resource access must enforce ownership/tenant authorization.

Protect against:

* IDOR;
* BOLA;
* horizontal privilege escalation.

---

# 21. Activity / Audit History

The business should be able to see basic activity for a request.

Examples:

* request created;
* request sent;
* document uploaded;
* reminder sent;
* request completed.

Do not build a sophisticated audit platform.

The purpose is visibility into what happened.

---

# 22. Notifications

For MVP, email is the only notification channel.

Do NOT implement:

* WhatsApp;
* SMS;
* push notifications;
* Slack;
* Microsoft Teams.

These may be considered later.

---

# 23. Payments

Payments are not required for the first technical MVP.

The architecture should not prevent adding Stripe later.

When payment is implemented:

* Stripe webhooks must be the source of truth;
* webhook signatures must be verified;
* webhook processing must be idempotent;
* never trust payment status supplied by the browser.

Initial pricing can be validated before implementing complex subscription management.

---

# 24. Analytics

We need enough analytics to understand whether the product is being used.

Track events such as:

* landing_view;
* signup_started;
* signup_completed;
* client_created;
* request_created;
* request_sent;
* client_link_opened;
* upload_started;
* document_uploaded;
* request_completed;
* reminder_sent.

Do not collect unnecessary personal information in analytics.

Do not store document contents in analytics.

Do not build a custom analytics dashboard for the MVP.

---

# 25. Security Requirements

Security is a product requirement, not a later task.

The application must protect against at minimum:

* IDOR/BOLA;
* CSRF;
* XSS;
* SQL injection;
* mass assignment;
* insecure direct file access;
* path traversal;
* malicious uploads;
* authorization bypass;
* rate-limit bypass where practical;
* session/authentication vulnerabilities;
* leaked secrets;
* sensitive information in logs.

All authorization must happen server-side.

All user input must be validated.

All output rendered into HTML must be safely escaped unless explicitly trusted.

Use Laravel's built-in security mechanisms whenever possible.

Do not invent custom cryptography.

---

# 26. Privacy

The product handles potentially sensitive client documents.

Principles:

* collect minimum necessary information;
* private file storage;
* no document contents in logs;
* no unnecessary document persistence;
* configurable retention;
* securely delete expired files;
* do not expose files through public URLs;
* do not expose sensitive data in analytics.

---

# 27. Queue

The following operations should be asynchronous:

* email sending;
* reminder processing;
* file cleanup;
* other operations that may become slow.

Use Laravel queues.

Do not introduce microservices.

---

# 28. Scheduled Tasks

The application will require scheduled jobs for:

* sending reminders;
* expiring requests;
* deleting expired files;
* other periodic cleanup.

Use Laravel's scheduler.

---

# 29. Out of Scope

The following are explicitly NOT part of the initial MVP:

* CRM;
* accounting software;
* invoicing;
* document OCR;
* AI document classification;
* AI extraction;
* e-signatures;
* digital signatures;
* WhatsApp;
* SMS;
* mobile application;
* native desktop application;
* Google Drive integration;
* Dropbox integration;
* OneDrive integration;
* QuickBooks integration;
* Xero integration;
* Salesforce integration;
* Zapier integration;
* API for third-party developers;
* team permissions;
* advanced roles;
* enterprise SSO;
* advanced workflow automation;
* custom branding;
* white-labeling;
* multiple organizations per user;
* advanced analytics;
* complex reporting;
* document editing;
* document preview processing;
* antivirus infrastructure unless later required;
* complex billing plans.

If a feature is not explicitly required by this document, do not implement it without approval.

---

# 30. Technical Constraints

Initial stack:

* Laravel;
* PHP;
* Vue 3;
* Inertia.js;
* PostgreSQL;
* Redis;
* Docker;
* Laravel Queue;
* Laravel Scheduler.

Use the simplest architecture that satisfies the requirements.

Do not introduce:

* microservices;
* Kubernetes;
* event buses;
* message brokers other than the required queue;
* unnecessary abstractions;
* unnecessary third-party packages.

Prefer Laravel-native functionality.

---

# 31. Definition of Done

A feature is considered complete only when:

* it works end-to-end;
* authorization is enforced server-side;
* validation exists;
* error states are handled;
* relevant tests exist;
* no obvious security vulnerability was introduced;
* the implementation is consistent with existing architecture;
* unnecessary complexity was avoided.

---

# 32. Core Product Principle

The goal is NOT to build the most complete document-management platform.

The goal is to validate:

> **Will small businesses pay to stop chasing clients for documents?**

When choosing between two implementation approaches, prefer the one that gets us to a usable, secure MVP faster while keeping a clean path for future expansion.
