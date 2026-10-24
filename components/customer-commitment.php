<?php
/**
 * Customer Commitment component
 */
?>
<section class="customer-commitment-section" id="commitment" data-aos="fade-up">
    <div class="container">
        <div class="commitment-content">
            <div class="commitment-title" data-aos="fade-up">
                <div class="commitment-subtitle">OUR PROMISE</div>
                <h2 class="commitment-heading">Customer Commitment</h2>
            </div>
            <div class="commitment-text-wrapper" data-aos="fade-up" data-aos-delay="100">
                <p class="commitment-text">
                    Our promise is simple — <strong>purity, consistency, and care</strong> in every drop.<br />
                    Because your trust deserves nothing less.
                </p>
            </div>
        </div>
    </div>
</section>

<style>
    :root {
        --commitment-primary: #3b8ebf;
        --commitment-secondary: #0b2e4e;
        --commitment-text: #5a6b7b;
        --commitment-bg: #f6fbff;
        --commitment-white: #ffffff;
    }

    .customer-commitment-section {
        background: linear-gradient(135deg, var(--commitment-bg) 0%, var(--commitment-white) 100%);
        padding: 80px 0;
        position: relative;
        overflow: hidden;
    }

    .customer-commitment-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent 0%, var(--commitment-primary) 50%, transparent 100%);
    }

    .commitment-content {
        max-width: 800px;
        margin: 0 auto;
        text-align: center;
        background: rgba(255, 255, 255, 0.8);
        border-radius: 20px;
        padding: 60px 40px;
        box-shadow: 0 8px 30px rgba(74, 210, 226, 0.1);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(74, 210, 226, 0.1);
        transition: all 0.4s ease;
        position: relative;
        overflow: hidden;
    }

    .commitment-content::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(74, 210, 226, 0.05), transparent);
        transition: left 0.6s ease;
    }

    .commitment-content:hover {
        transform: translateY(-8px);
        box-shadow: 0 15px 40px rgba(74, 210, 226, 0.15);
    }

    .commitment-content:hover::before {
        left: 100%;
    }

    .commitment-title {
        margin-bottom: 30px;
    }

    .commitment-subtitle {
        color: var(--commitment-primary);
        font-weight: 600;
        font-size: 0.9rem;
        letter-spacing: 2px;
        text-transform: uppercase;
        margin-bottom: 10px;
    }

    .commitment-heading {
        font-size: clamp(2rem, 4vw, 2.8rem);
        font-weight: 700;
        color: var(--commitment-secondary);
        margin-bottom: 0;
        line-height: 1.2;
    }

    .commitment-text-wrapper {
        position: relative;
    }

    .commitment-text {
        font-size: clamp(1.1rem, 2.5vw, 1.3rem);
        color: var(--commitment-text);
        line-height: 1.8;
        font-weight: 400;
        margin: 0;
        position: relative;
    }

    .commitment-text strong {
        color: var(--commitment-primary);
        font-weight: 700;
        position: relative;
    }

    .commitment-text strong::after {
        content: '';
        position: absolute;
        bottom: -2px;
        left: 0;
        width: 100%;
        height: 2px;
        background: linear-gradient(90deg, var(--commitment-primary), transparent);
        opacity: 0.3;
    }

    /* Animation keyframes */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes shimmer {
        0% {
            transform: translateX(-100%);
        }
        100% {
            transform: translateX(100%);
        }
    }

    .commitment-content {
        animation: fadeInUp 1s ease forwards;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .customer-commitment-section {
            padding: 60px 0;
        }

        .commitment-content {
            padding: 40px 25px;
            margin: 0 15px;
        }

        .commitment-subtitle {
            font-size: 0.8rem;
            letter-spacing: 1.5px;
        }

        .commitment-text {
            font-size: 1rem;
            line-height: 1.6;
        }
    }

    @media (max-width: 480px) {
        .commitment-content {
            padding: 30px 20px;
            margin: 0 10px;
        }

        .commitment-heading {
            font-size: 1.8rem;
        }
    }
</style>
