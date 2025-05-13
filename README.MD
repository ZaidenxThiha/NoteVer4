My Note - A Simple Note-Taking Web Application
My Note is a PHP-based web application that allows users to register, log in, reset their passwords, and receive real-time activation links via WebSocket. The application uses Docker for deployment, MySQL for persistent storage, MailHog for email testing, and Ratchet for WebSocket communication.
Features

User Registration: Register with an email, display name, and password. An activation link and OTP are sent via email and WebSocket.
User Login: Log in with registered email and password, even before account activation. Unauthenticated users are redirected to the login page.
Account Activation: Activate your account via an email link. Until activated, a prominent notification is displayed on all pages, indicating the account is unverified and prompting email verification.
Password Reset: Request a password reset via email, receive an OTP and reset link, and set a new password. Accessible without login.
Real-Time Activation: Receive activation links in real-time via WebSocket during registration.
Email Testing: Uses MailHog to capture and display emails sent during registration and password reset.
Secure Password Storage: Passwords are hashed using bcrypt for enhanced security.
Dockerized Deployment: Runs in Docker containers for easy setup and deployment.

Technologies Used

PHP: Backend logic and web pages (index.php, login.php, register.php, etc.).
Ratchet: PHP WebSocket library for real-time communication (websocket_server.php).
MySQL: Database for storing user data.
MailHog: Email testing tool to capture and display emails.
PHPMailer: Library for sending emails via SMTP.
Docker: Containerization for deployment.
Apache: Web server for serving PHP pages.

Directory Structure
project/
├── public/
│   ├── index.php           # Entry point, redirects to login
│   ├── login.php           # Login page
│   ├── register.php        # Registration page
│   ├── welcome.php         # Welcome page after registration
│   ├── home.php            # Home page after login
│   ├── activate.php        # Account activation page
│   ├── reset_request.php   # Password reset request page
│   ├── reset_password.php  # Password reset page
│   ├── logout.php          # Logout page
│   ├── websocket_server.php # Ratchet WebSocket server
├── Dockerfile              # Dockerfile for PHP/Apache container
├── docker-compose.yml      # Docker Compose configuration
├── init.sql               # MySQL schema initialization script
└── README.md              # Project documentation

Prerequisites

Docker: Ensure Docker and Docker Compose are installed on your system.
Git: To clone the repository (optional).

Setup Instructions
1. Clone the Repository (Optional)
If the project is hosted in a Git repository, clone it:
git clone <repository-url>
cd project

2. Start the Application

Navigate to the project directory and start the Docker containers:

docker-compose up --build -d


This will build and start the following services:

mysql: MySQL database on port 3306.
phpapp: PHP/Apache server on port 80 and Ratchet WebSocket server on port 8080.
mailhog: MailHog email server on ports 1025 (SMTP) and 8025 (web UI).


Wait ~30 seconds for the containers to initialize.


3. Access the Application

Login Page: http://localhost/login.php (unauthenticated users are redirected here)
Registration Page: http://localhost/register.php (requires login)
Welcome Page: http://localhost/welcome.php (requires login, shown after registration)
Home Page: http://localhost/home.php (requires login)
MailHog UI: http://localhost:8025 (to view emails)

4. Test the Features

Access Without Login:

Try accessing http://localhost/register.php, http://localhost/home.php, or http://localhost/welcome.php without logging in.
You should be redirected to http://localhost/login.php.


Register a User:

Log in first at http://localhost/login.php (you may need to register a user initially, which requires temporary access; see note below).
Go to http://localhost/register.php.
Fill in your email, display name, password, and confirm password.
Submit the form. You should see the activation link in the browser (via WebSocket) and receive an email in MailHog (http://localhost:8025).
You will be automatically logged in and redirected to the home page, but a notification will indicate that your account is unverified.


Access Features Before Activation:

Navigate to http://localhost/home.php or other pages. You should see a notification: "Your account is unverified. Please check your email to complete the activation process."
All functionalities (e.g., viewing the home page) are accessible despite the unverified status.


Activate Account:

Click the activation link in the email received from MailHog.
You will be redirected to the home page, and the unverified notification should disappear.


Log In:

Log out and go to http://localhost/login.php.
Enter your email and password to log in.


Reset Password:

From the login page, click "Reset Password" to go to http://localhost/reset_request.php (accessible without login).
Enter your registered email and submit.
Check MailHog for the reset email containing an OTP and reset link.
Click the reset link to go to http://localhost/reset_password.php (accessible without login), enter the OTP, a new password, and confirm the new password.
Log in with the new password.



Note: To register the first user, you may need to temporarily comment out the authentication check in register.php (lines 5-8) to allow initial access. After registering the first user, uncomment the check to enforce the login requirement.
5. Stop the Application

Stop and remove the containers:docker-compose down


To reset the database (delete all data), remove the MySQL volume:docker volume rm project_mysql-data



Troubleshooting
Database Schema Issues
If you encounter errors like Column not found: 1054 Unknown column 'reset_otp', the database schema may not have been updated. Apply the schema changes manually:
docker exec -it <mysql-container-id> mysql -uroot -pYourStrong@Passw0rd noteapp -e "ALTER TABLE users ADD COLUMN reset_otp VARCHAR(6), ADD COLUMN reset_token VARCHAR(255), ADD COLUMN reset_expiry DATETIME;"

Replace <mysql-container-id> with the container ID of the mysql service (find it using docker-compose ps).
Email Not Received in MailHog

Check the phpapp logs for email-sending errors:docker-compose logs phpapp


Ensure the mailhog container is running and accessible on port 1025:docker-compose ps


Test email sending manually:docker exec -it <phpapp-container-id> bash
curl -v --mail-from "no-reply@noteapp.com" --mail-rcpt "test@example.com" -T <(echo -e "Subject: Test Email\n\nThis is a test email.") smtp://mailhog:1025



WebSocket Connection Issues

Ensure the WebSocket server is running on port 8080:docker-compose logs phpapp

Look for "WebSocket server running on port 8080".
Open the browser console (F12) on the registration page and check for WebSocket errors.

Contributing
Feel free to submit issues or pull requests to improve the application. Areas for enhancement include:

Adding email verification for password reset requests.
Implementing rate limiting for password reset requests.
Adding user profile management features.

License
This project is licensed under the MIT License. See the LICENSE file for details (if applicable).
