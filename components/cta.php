<?php
/**
 * Call to Action Section component - Fully Responsive
 */
?>
<section class="cta-section" id="cta" data-aos="fade-up">
    <div class="cta-content">
        <h2 class="cta-title"  data-aos="fade-right">Ready to Hydrate?</h2>
        <p class="cta-text" data-aos="fade-left">
            Experience the purest water that nature has to offer. 
            Join thousands of satisfied customers who trust LIYAS for their daily hydration needs.
        </p>
        <div class="cta-buttons" data-aos="fade-up">
            <a href="../products.php" class="cta-button primary">Order Now</a>
            <!--<a href="about.php" class="cta-button secondary">Learn More</a>-->
        </div>
    </div>
    
    <!-- Background Elements -->
    <div class="cta-bg-elements">
        <div class="cta-bubble cta-bubble-1"></div>
        <div class="cta-bubble cta-bubble-2"></div>
        <div class="cta-bubble cta-bubble-3"></div>
    </div>

    <style>
        .cta-section {
            background: linear-gradient(135deg, #10383a 0%, #10383a 100%);
            color: var(--white);
            padding: var(--space-xxxl) 0;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .cta-content {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 var(--space-md);
            position: relative;
            z-index: 2;
        }

        .cta-title {
            font-size: clamp(var(--text-3xl), 5vw, var(--text-6xl));
            font-weight: 800;
            margin-bottom: var(--space-md);
            color: var(--white);
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            line-height: 1.1;
        }

        .cta-text {
            font-size: clamp(var(--text-base), 2.5vw, var(--text-xl));
            line-height: 1.6;
            margin-bottom: var(--space-xl);
            color: rgba(255, 255, 255, 0.95);
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .cta-buttons {
            display: flex;
            gap: var(--space-md);
            justify-content: center;
            flex-wrap: wrap;
        }

        .cta-button {
            padding: var(--space-md) var(--space-xl);
            font-size: var(--text-base);
            font-weight: 600;
            border-radius: 50px;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-block;
            cursor: pointer;
            border: 2px solid transparent;
            min-width: 150px;
            text-align: center;
        }

        .cta-button.primary {
            background: var(--white);
            color: var(--primary);
            box-shadow: 0 4px 15px rgba(255, 255, 255, 0.3);
        }

        .cta-button.primary:hover {
            background: var(--primary-dark);
            color: var(--white);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }

        .cta-button.secondary {
            background: transparent;
            color: var(--white);
            border: 2px solid rgba(255, 255, 255, 0.8);
        }

        .cta-button.secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--white);
            transform: translateY(-3px);
        }

        /* Background Elements */
        .cta-bg-elements {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: none;
        }

        .cta-bubble {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            animation: float 6s ease-in-out infinite;
        }

        .cta-bubble-1 {
            width: 80px;
            height: 80px;
            top: 20%;
            left: 10%;
            animation-delay: 0s;
        }

        .cta-bubble-2 {
            width: 120px;
            height: 120px;
            top: 60%;
            right: 15%;
            animation-delay: 2s;
        }

        .cta-bubble-3 {
            width: 60px;
            height: 60px;
            bottom: 30%;
            left: 20%;
            animation-delay: 4s;
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-20px);
            }
        }

        /* Responsive Styles */
        @media (max-width: 767px) {
            .cta-section {
                padding: var(--space-xxl) 0;
            }
            
            .cta-content {
                padding: 0 var(--space-sm);
            }
            
            .cta-title {
                font-size: clamp(var(--text-2xl), 6vw, var(--text-4xl));
                margin-bottom: var(--space-sm);
            }
            
            .cta-text {
                font-size: var(--text-sm);
                margin-bottom: var(--space-lg);
            }
            
            .cta-buttons {
                flex-direction: column;
                align-items: center;
                gap: var(--space-sm);
            }
            
            .cta-button {
                width: 100%;
                max-width: 280px;
                padding: var(--space-sm) var(--space-lg);
                font-size: var(--text-sm);
            }

            .cta-bubble {
                display: none; /* Hide bubbles on mobile for better performance */
            }
        }

        @media (min-width: 768px) and (max-width: 991px) {
            .cta-section {
                padding: var(--space-xxxl) 0;
            }
            
            .cta-title {
                font-size: clamp(var(--text-4xl), 4vw, var(--text-5xl));
            }
            
            .cta-text {
                font-size: var(--text-lg);
            }
            
            .cta-button {
                padding: var(--space-sm) var(--space-lg);
                font-size: var(--text-sm);
            }
        }

        @media (min-width: 992px) {
            .cta-section {
                padding: var(--space-xxxl) 0;
            }
            
            .cta-title {
                font-size: clamp(var(--text-5xl), 3vw, var(--text-6xl));
            }
            
            .cta-text {
                font-size: var(--text-xl);
            }
            
            .cta-button {
                padding: var(--space-md) var(--space-xl);
                font-size: var(--text-base);
            }
        }

        /* Accessibility */
        @media (prefers-reduced-motion: reduce) {
            .cta-bubble {
                animation: none;
            }
            
            .cta-button {
                transition: none;
            }
        }

        /* Focus styles */
        .cta-button:focus {
            outline: 2px solid var(--white);
            outline-offset: 2px;
        }

        /* High contrast mode */
        @media (prefers-contrast: high) {
            .cta-section {
                background: #0066cc;
            }
            
            .cta-button.primary {
                background: #ffffff;
                color: #000000;
            }
        }
    </style>
</section>