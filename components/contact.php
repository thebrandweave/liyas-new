

<?php
// /**
//  * Contact Us component – PHPMailer via Gmail SMTP
//  */

// use PHPMailer\PHPMailer\PHPMailer;
// use PHPMailer\PHPMailer\Exception;

// // Make sure PHPMailer is installed (composer require phpmailer/phpmailer)
// require_once __DIR__ . '/../vendor/autoload.php';

// $feedback = "";

// if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_contact'])) {
    
//     // Sanitize inputs
//     $name    = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS);
//     $email   = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
//     $phone   = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_SPECIAL_CHARS);
//     $reason  = filter_input(INPUT_POST, 'reason', FILTER_SANITIZE_SPECIAL_CHARS);
//     $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_SPECIAL_CHARS);

//     if ($email) {
//         $mail = new PHPMailer(true);

//         try {
//             // --- GMAIL SMTP SETTINGS ---
//             $mail->isSMTP();
//             $mail->Host       = 'smtp.gmail.com';
//             $mail->SMTPAuth   = true;
//             $mail->Username   = 'mufizmalar@gmail.com';   
//             $mail->Password   = 'njqzjaumxueqkqgt';     
//             $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
//             $mail->Port       = 587;                        

//             // --- RECIPIENTS ---
//             $mail->setFrom('mufizmalar@gmail.com', 'Liyas Web Form');
//             $mail->addAddress('mufizmalar@gmail.com');     
//             $mail->addReplyTo($email, $name);              

//             // --- CONTENT ---
//             $mail->isHTML(true);
//             $mail->Subject = 'New Enquiry: ' . $reason;
            
//             $mail->Body = "
//             <html>
//             <body style='font-family: Arial, sans-serif;'>
//                 <h2>New Contact Request</h2>
//                 <p><strong>Name:</strong> {$name}</p>
//                 <p><strong>Email:</strong> {$email}</p>
//                 <p><strong>Phone:</strong> {$phone}</p>
//                 <p><strong>Message:</strong><br>" . nl2br($message) . "</p>
//             </body>
//             </html>";

//             $mail->send();
//             $feedback = '<div class="alert alert-success text-center mb-4" style="border-radius:16px;">Sent successfully! We will contact you soon.</div>';
            
//         } catch (Exception $e) {
//             $feedback = '<div class="alert alert-danger text-center mb-4" style="border-radius:16px;">Submission failed. Please try again later.</div>';
//         }
//     } else {
//         $feedback = '<div class="alert alert-warning text-center mb-4" style="border-radius:16px;">Invalid email address.</div>';
//     }
// }
?>

<section class="contact-section" id="contact" data-aos="fade-up">
   <div class="contact-bg-fixed"></div>

    <div class="container position-relative">
        <div class="contact-watermark">LIYAS</div>

        <div class="contact-form-wrapper">
            <iframe name="hidden_iframe" id="hidden_iframe" style="display:none;" onload="if(typeof sent!=='undefined'){window.location.hash='#contact'; alert('Thank you! Your message has been sent.');}"></iframe>

            <form action="https://docs.google.com/forms/d/e/1FAIpQLSfGtYpwEb-j-22J57O2NxbU0EWtszqg2pbqmwLVm1pKykXhGA/formResponse" 
                  method="post" 
                  target="hidden_iframe" 
                  onsubmit="sent=true;" 
                  class="contact-form">

                <div class="row g-4 align-items-stretch">
                    <div class="col-lg-6">
                        <div class="row g-3">
                            <div class="col-12">
                                <input type="text" class="form-control contact-input" name="entry.736636704" placeholder="Your name" required>
                            </div>
                            <div class="col-12">
                                <input type="email" class="form-control contact-input" name="entry.1291214373" placeholder="Enter your E-Mail" required>
                            </div>
                            <div class="col-12">
                                <input type="tel" class="form-control contact-input" name="entry.1841718523" placeholder="Contact number">
                            </div>
                            <div class="col-12">
                                <select class="form-control contact-input" name="entry.2138610591" required>
                                    <option value="">Reason for contacting</option>
                                    <option value="Bulk Orders">Bulk Orders</option>
                                    <option value="Distributorship">Distributorship</option>
                                    <option value="General Enquiry">General Enquiry</option>
                                    <option value="Feedback">Feedback</option>
                                    <option value="Support">Support</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <textarea class="form-control contact-textarea h-100" name="entry.524123660" placeholder="Tell us how we can help you…" rows="9" required></textarea>
                    </div>
                </div>

                <div class="text-center mt-5">
                    <button type="submit" class="btn contact-submit px-5">
                        Send Message
                    </button>
                    <p class="contact-note">
                        We usually respond within 24 hours.
                    </p>
                </div>
            </form>
        </div>
    </div>

    <svg class="wave-svg" viewBox="0 0 1440 390" xmlns="http://www.w3.org/2000/svg" style="margin-top: -50px;">
        <path fill="#ffffff" d="M0,400L0,150C94.7,117.9,189.4,85.8,277,76
        C364.5,66.1,444.8,78.4,507,92
        C569.1,105.5,613,120.3,694,122
        C775,123.6,893.1,112.2,986,128
        C1078.8,143.7,1146.5,186.7,1218,195
        C1289.4,203.2,1364.7,176.6,1440,150
        L1440,400L0,400Z"/>
    </svg>

    <style>
        :root {
            --primary: #3bb6c4;
            --secondary: #6c757d;
            --space-xxxl: 80px;
        }

        .contact-section {
            padding: var(--space-xxxl) 0 0;
            position: relative;
            overflow: hidden;
            min-height: 100vh;
        }

        .contact-bg-fixed {
            position: absolute;
            inset: 0;
            background: url('https://images.unsplash.com/photo-1559827260-dc66d52bef19?w=1600&q=80') center/cover no-repeat;
            z-index: -2;
        }

        .contact-bg-fixed::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(240,249,255,.95), rgba(255,255,255,.9));
        }

        .contact-form-wrapper {
            max-width: 900px;
            margin: 0 auto;
            position: relative;
            z-index: 10;
        }

        .contact-form {
            position: relative;
            padding: 3rem 0;
        }

        .contact-form::before {
            content: "";
            position: absolute;
            inset: -60px;
            background: radial-gradient(circle at center, rgba(74,210,226,0.12), transparent 70%);
            z-index: -1;
        }

        .contact-form .form-control {
            background: rgba(255,255,255,0.88);
            border: 1px solid #e6f0f4;
            border-radius: 16px;
            padding: 15px 18px;
            transition: all .3s ease;
        }

        .contact-form .form-control:focus {
            background: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 12px 30px rgba(74,210,226,.25);
            outline: none;
        }

        .contact-textarea { resize: none; }

        .contact-submit {
            background: linear-gradient(135deg, #3bb6c4, #4ad2e2);
            color: #fff;
            border-radius: 50px;
            padding: 14px 44px;
            font-weight: 600;
            transition: .3s;
            border: none;
        }

        .contact-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(74,210,226,.35);
            color: #fff;
        }

        .contact-note {
            margin-top: .75rem;
            font-size: .9rem;
            color: var(--secondary);
        }

        .contact-watermark {
            position: absolute;
            bottom: 40px;
            right: 30px;
            font-size: 6rem;
            font-weight: 800;
            color: rgba(74,210,226,.08);
            pointer-events: none;
            z-index: 1;
        }

        .wave-svg { width: 100%; height: auto; display: block; }

        @media (max-width: 768px) {
            .contact-form { padding: 2rem 0; }
            .contact-watermark { font-size: 3.5rem; }
        }
    </style>
</section>