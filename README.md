Hania’s Health Hub with AI Facilitation

A database-driven hospital management system built with PHP, MySQL, XAMPP, and a secure Node.js AI integration. The application helps hospital staff manage patients, doctor visits, laboratory orders, billing, payments, reports, and report-based questions through an AI assistant.



Academic/demo project: This application uses fictional data only. Do not use it with real patient, staff, or hospital information without adding appropriate security, privacy, compliance, and access-control safeguards.



Contents





Overview



Features



Screenshots



Technology stack



Project structure



Database



Local setup



AI report assistant



Security



Testing checklist



Project status

Overview

Hania’s Health Hub with AI Facilitation is a local hospital-management web application designed to connect common hospital workflows in one place:





Register and manage patients.



Record doctor visits and prescriptions.



Order laboratory tests and update results.



Record payments and review patient balances.



Generate date-filtered operational reports.



Ask the AI assistant questions using the report data supplied by the application.

The PHP application runs through Apache in XAMPP. MySQL stores the application records, and a separate Node.js server keeps the AI API key away from browser code.

Features

Dashboard





Displays key hospital activity totals.



Shows total registered patients.



Shows today’s visits.



Shows pending laboratory tests.



Shows today’s collection.



Provides navigation to the main modules.

Patient management





Registers new patients.



Generates a unique UH ID for each patient.



Displays the patient list.



Shows patient details.



Supports editing patient information.



Uses fictional sample records for demonstration.

Doctor visits





Selects a patient and doctor.



Records the visit date.



Records the consultation fee.



Stores the complaint or problem description.



Stores prescriptions and medicine instructions.

Laboratory management





Uses an existing test catalogue with prices.



Places a laboratory order for a patient.



Records the ordering doctor.



Saves the test fee at the time of ordering.



Tracks pending and completed tests.



Stores fictional test results and result dates.

Billing and payments





Reviews patient-related charges.



Calculates recorded charges and payments.



Displays outstanding balances.



Records payments using Cash, Card, or bKash.



Supports visit-fee and laboratory-test payment categories.

Reports





Filters report data by a from-date and to-date.



Shows daily collection totals.



Separates visit payments from laboratory payments.



Shows patient registration activity.



Shows doctor-wise visit counts and fees.



Shows laboratory orders by status.



Includes report totals and print support.

AI report assistant





Displays report details beside the chat interface.



Sends report data and a staff question to the local Node.js server.



Uses ChatterMate for report-based answers.



Keeps the API key outside the browser.



Is designed to answer only from the supplied report data.



Does not edit or delete hospital records.

Screenshots

The screenshots below are stored in the screenshots directory and are listed in the same order as the main user workflow.

1. Dashboard



2. Patient registration



3. Patient list



4. Patient details



5. Edit patient details



6. Doctor visit



7. Laboratory tests



8. Billing and payments



9. Reports



10. AI report assistant





The screenshot filenames above match the PNG files in this project’s screenshots folder. GitHub paths are case-sensitive, so keep the spelling and capitalization unchanged.

Technology stack







Layer



Technology





Frontend



HTML, CSS, JavaScript





Application backend



PHP





Database



MySQL





Local web server



Apache through XAMPP





Database administration



phpMyAdmin





AI service bridge



Node.js using built-in modules





AI provider



ChatterMate





Development environment



Visual Studio Code

Project structure

hanias_health_hub/
├── .env                    # Local secret; never commit this file
├── .gitignore              # Git exclusions
├── README.md               # Project documentation
├── index.php               # Main entry page
├── server.js               # Node.js AI API server
├── css/
│   └── style.css           # Shared application styling
├── includes/
│   ├── db.php              # MySQL connection
│   ├── header.php          # Shared navigation and page header
│   └── footer.php          # Shared footer and scripts
├── js/
│   └── script.js           # Shared browser-side JavaScript
├── pages/
│   ├── ai_chat.php
│   ├── billings.php
│   ├── dashboard.php
│   ├── doctor_visit.php
│   ├── lab_tests.php
│   ├── patient_details.php
│   ├── patient_edit.php
│   ├── patient_list.php
│   ├── patient_registration.php
│   └── reports.php
├── reports/
│   └── daily_collection.php
├── screenshots/
│   ├── AI_report_chat.png
│   ├── billing.png
│   ├── dashboard.png
│   ├── doctor_visit.png
│   ├── edit_patient_details.png
│   ├── lab_tests.png
│   ├── patient_list.png
│   ├── register_patient.png
│   ├── reports.png
│   └── view_patient_details.png
└── server/
    └── ask_ai.php


Database

The application uses the following core MySQL tables:





patients — patient profiles and UH IDs.



doctors — doctor names, departments, and consultation fees.



visits — doctor visits, complaints, prescriptions, fees, and visit dates.



lab_tests — laboratory test catalogue and prices.



lab_orders — patient laboratory orders, fees, statuses, results, and dates.



payments — patient payments, payment methods, payment categories, and dates.

The current application uses the following important column conventions:





visits.complaint



visits.prescription



lab_orders.patient_id



lab_orders.doctor_id



lab_orders.uh_id



lab_orders.test_id



lab_orders.fee



lab_orders.status



lab_orders.result



lab_orders.result_date



lab_orders.order_date

Payments are associated with patients using uh_id. Because payments are not linked to an individual visit or laboratory order, outstanding balances are calculated at the patient level rather than against a specific charge.

Local setup

Prerequisites

Install or prepare the following:





XAMPP with Apache and MySQL.



Node.js.



Visual Studio Code or another code editor.



A modern web browser.



A ChatterMate API key for the AI assistant.

1. Place the project in XAMPP

Copy or clone the project into the XAMPP htdocs directory. The expected local path is:

C:\xampp\htdocs\hanias_health_hub


2. Start Apache and MySQL

Open the XAMPP Control Panel and start:





Apache



MySQL

3. Configure the database





Open phpMyAdmin at http://localhost/phpmyadmin/.



Create or select a database named hanias_health_hub.



Create the application tables using the project schema.



Open includes/db.php.



Confirm that the host, database name, username, and password match your local MySQL configuration.

4. Configure the AI key

Create a file named .env in the project root, beside server.js:

CHATTERMATE_API_KEY=your_private_key_here


Use your real key only in your local .env file. Do not paste it into this README, PHP files, JavaScript files, screenshots, GitHub, or a project ZIP.

5. Start the AI server

Open a terminal in the project root and run:

cd C:\xampp\htdocs\hanias_health_hub
node --env-file=.env server.js


Keep the terminal open while using the AI report assistant. If the server provides a health endpoint, check it at:

http://localhost:3000/health


6. Open the application

With Apache and MySQL running, open:

http://localhost/hanias_health_hub/


Sample data

The project uses fictional sample records for demonstration and testing. Sample data should include patients, doctors, visits, laboratory orders, and payments so that the reports and AI assistant can be tested with realistic values.

If you use a SQL seed script, check whether it is safe to run more than once. Running a non-repeatable seed script multiple times may create duplicate records.

GitHub security

Before pushing the project to GitHub:





Confirm that .env is listed in .gitignore.



Confirm that the real API key is not staged or committed.



Do not commit real patient data or database exports.



Use fictional data in screenshots and demonstrations.



Do not include passwords, API keys, or private configuration files.

Recommended .gitignore entries:

.env
.env.*
!.env.example
node_modules/
*.log
*.sql


You may create an .env.example file containing only a placeholder:

CHATTERMATE_API_KEY=your_key_here


Testing checklist





Dashboard loads successfully.



Patient registration creates a new patient and UH ID.



Patient list displays saved patients.



Patient details page opens correctly.



Patient editing updates the selected record.



Doctor visit records save with the correct patient, doctor, complaint, prescription, fee, and date.



Laboratory orders save with the correct test and fee.



Laboratory results and statuses can be updated.



Billing displays charges, payments, and outstanding balance.



Payment methods include Cash, Card, and bKash.



Reports respond correctly to date filters.



Report totals match the underlying database records.



Reports can be printed.



The AI server starts successfully.



The AI assistant answers using the displayed report data.



The API key is not exposed in browser code or GitHub.

Project status

This project is a local academic/demo system. It is not intended for production use with real patient data. A production deployment would require authentication, authorization, encryption, audit logging, backups, privacy controls, monitoring, and compliance safeguards.

License

This project is intended for educational and demonstration purposes. Add a license here if you plan to publish the project for reuse.