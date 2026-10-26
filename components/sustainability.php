<?php
/**
 * Sustainability Section component (Scroll-Triggered Text Highlight)
 * - Restored and fully mobile-responsive
 */
?>

<section class="sustainability-section">
    <div class="pinned-content">
        <h1 class="scroll-text" id="scroll-text">
            <span class="slogan-icon" aria-hidden="true"><i class="bx bx-recycle"></i></span>
            Sustainability – A Drop Towards Change<br><br>
            We believe every bottle can make a difference. From eco-friendly production to reusable packaging, our mission is to ensure every sip supports a sustainable tomorrow.
        </h1>
    </div>
</section>

<style>
    .sustainability-section {
        position: relative;
        height: 240vh; /* Creates space for the scroll effect */
        width: 100%;
    }

    .pinned-content {
        position: sticky;
        top: 0;
        height: 100vh;
        height: 100dvh;
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.92) 0%, rgba(248, 250, 252, 0.92) 100%), 
                    url('https://images.unsplash.com/photo-1559827260-dc66d52bef19?w=1600&q=80') center/cover;
        display: flex;
        justify-content: center;
        align-items: center;
        overflow: hidden;
        padding: 2rem 1.5rem;
    }

    .scroll-text {
        width: min(90%, 1200px);
        font-size: clamp(1.8rem, 3.5vw, 2.8rem);
        text-align: center;
        line-height: 1.8;
        font-weight: 600;
        color: #3b8ebf;
        margin: 0 auto;
        text-transform: uppercase;
        position: relative;
    }

    .scroll-text span {
        display: inline-block;
        color: #3b8ebf;
        transition: color 0.3s ease, transform 0.3s ease, text-shadow 0.3s ease;
        letter-spacing: 1.5px;
    }

    /* Slogan Icon Styling */
    .slogan-icon {
        display: block !important;
        margin: 0 auto 12px;
        font-size: 2.2rem;
        color: #10b981 !important;
        filter: drop-shadow(0 0 6px rgba(16, 185, 129, 0.4));
        transition: none !important;
    }

    .slogan-icon i {
        color: inherit;
        display: inline-block;
    }

    .scroll-text span.show-down {
        color: #10b981;
        transform: translateY(-2px);
        text-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
    }

    .scroll-text span.show-up {
        color: #3b8ebf;
        transform: translateY(0);
        text-shadow: none;
    }

    /* Mobile Responsive Optimizations */
    @media (max-width: 768px) {
        .sustainability-section {
            height: 160vh; /* Smooth, natural scroll distance on touch */
        }

        .pinned-content {
            padding: 1.5rem 1rem;
        }

        .scroll-text {
            width: 100%;
            max-width: 540px;
            font-size: clamp(1.05rem, 4.2vw, 1.35rem);
            line-height: 1.55;
            letter-spacing: 0.5px;
        }

        .scroll-text span {
            letter-spacing: 0.5px;
        }

        .slogan-icon {
            font-size: 1.8rem;
            margin-bottom: 8px;
        }
    }

    @media (max-width: 480px) {
        .sustainability-section {
            height: 140vh;
        }

        .scroll-text {
            font-size: clamp(0.95rem, 3.8vw, 1.15rem);
            line-height: 1.5;
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const textElement = document.getElementById("scroll-text");
        const section = document.querySelector(".sustainability-section");
        if (!textElement || !section) return;

        // Preserve icon and extract text
        const iconEl = textElement.querySelector('.slogan-icon');
        const iconHtml = iconEl ? iconEl.outerHTML : '';
        const textOnly = textElement.textContent;

        // Split words
        const words = textOnly.split(/\s+/).filter(Boolean);
        const spannedWords = words.map(w => `<span>${w}</span>`).join(" ");
        textElement.innerHTML = iconHtml + spannedWords;

        const spans = textElement.querySelectorAll("span");
        const animatableSpans = Array.from(spans).filter(span => !span.classList.contains('slogan-icon'));

        let lastScrollY = window.scrollY;

        window.addEventListener("scroll", () => {
            const rect = section.getBoundingClientRect();
            const scrollDirection = window.scrollY > lastScrollY ? "down" : "up";
            lastScrollY = window.scrollY;

            if (rect.top <= 0 && rect.bottom > window.innerHeight) {
                const totalScrollable = rect.height - window.innerHeight;
                const currentScroll = Math.abs(rect.top);
                const progress = Math.min(Math.max(currentScroll / totalScrollable, 0), 1);
                const index = Math.floor(progress * animatableSpans.length);

                animatableSpans.forEach((span, i) => {
                    if (scrollDirection === "down") {
                        if (i <= index) {
                            span.classList.add("show-down");
                            span.classList.remove("show-up");
                        }
                    } else {
                        if (i >= index) {
                            span.classList.add("show-up");
                            span.classList.remove("show-down");
                        }
                    }
                });
            }
        }, { passive: true });
    });
</script>
