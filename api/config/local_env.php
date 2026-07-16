<?php
declare(strict_types=1);

// Local dev secrets for XAMPP. This file is gitignored — never commit it.
// If you rotate the Twilio Auth Token, update TWILIO_AUTH_TOKEN here and save.
// No Apache restart needed — PHP loads this file on every request.

putenv('TWILIO_ACCOUNT_SID=ACd4c90fb471fd7a15a5cce77c99887a65');
putenv('TWILIO_AUTH_TOKEN=b11cc51d0cbf5b5e53794c08dcf52130');
putenv('TWILIO_VERIFY_SERVICE_SID=VA7e2e0224b0ff92112c975ce5e1795a4e');
