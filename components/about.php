<?php
// Asset base path (works for root & subfolders)
$script_path = $_SERVER['SCRIPT_NAME'];
$script_dir = dirname($script_path);
$path_segments = array_filter(explode('/', $script_dir));
$is_subdirectory = (count($path_segments) > 1);
$asset_base = $is_subdirectory ? '../' : '';
?>

<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: 'Poppins', sans-serif;
}

body {
  background: #ffffff;
  color: #1e293b;
}

/* ================= BRAND HERO ================= */
.about-hero {
  min-height: 85vh;
  background: linear-gradient(135deg, #e8fbff, #f6feff);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 6rem 8%;
}

.about-hero-inner {
  display: grid;
  grid-template-columns: 1.2fr 1fr;
  align-items: center;
  gap: 4rem;
  width: 100%;
  max-width: 1200px;
}

.about-hero-left .tag {
  display: inline-block;
  background: rgba(74, 210, 226, 0.15);
  color: #0b2e4e;
  padding: 6px 16px;
  border-radius: 999px;
  font-size: 0.85rem;
  font-weight: 600;
  margin-bottom: 1.5rem;
}

.brand-title {
  font-size: clamp(2.5rem, 7vw, 5.5rem);
  font-weight: 900;
  letter-spacing: 0.18em;
  color: #0b2e4e;
  margin-bottom: 0.3rem;
}

.brand-subtitle {
  font-size: clamp(1.5rem, 4vw, 2.8rem);
  font-weight: 700;
  margin-bottom: 1.2rem;
}

.brand-subtitle span {
  color: #3b8ebf;
}

.about-hero-left p {
  color: #5a6b7b;
  font-size: 1.05rem;
  max-width: 520px;
  margin-bottom: 2rem;
}

.hero-btns a {
  display: inline-block;
  padding: 12px 30px;
  border-radius: 30px;
  text-decoration: none;
  font-weight: 600;
  margin-right: 12px;
  transition: 0.3s;
}

.btn-primary {
  background: #3b8ebf;
  color: #fff;
}

.btn-primary:hover {
  background: #2cbac9;
}

.btn-outline {
  border: 2px solid #3b8ebf;
  color: #3b8ebf;
}

.btn-outline:hover {
  background: #3b8ebf;
  color: #fff;
}

.about-hero-right {
  display: flex;
  justify-content: center;
  align-items: center;
}

.about-hero-right img {
  width: 280px;
  max-width: 100%;
  height: auto;
  animation: floatBottle 6s ease-in-out infinite;
}

/* Bottle floating animation */
@keyframes floatBottle {
  0% { transform: translateY(0); }
  50% { transform: translateY(-12px); }
  100% { transform: translateY(0); }
}

/* ================= ABOUT UNIQUE ================= */
.about-unique {
  padding: 4rem 8%;
  background: linear-gradient(180deg, #ffffff 0%, #f3fdff 100%);
  overflow: hidden;
  width: 100%;
}

.about-unique-inner {
  display: grid;
  grid-template-columns: 1.2fr 1fr;
  align-items: center;
  gap: 4rem;
  max-width: 1200px;
  margin: auto;
}

.about-eyebrow {
  font-size: 0.85rem;
  color: #3b8ebf;
  font-weight: 600;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  display: inline-block;
  margin-bottom: 0.5rem;
}

.about-unique-left h2 {
  font-size: clamp(1.6rem, 4vw, 2.6rem);
  font-weight: 700;
  margin: 0.5rem 0 1rem;
  color: #0b2e4e;
  line-height: 1.25;
}

.about-unique-left p {
  color: #5a6b7b;
  font-size: 1rem;
  margin-bottom: 1.2rem;
  max-width: 520px;
  line-height: 1.6;
}

.about-unique-right {
  position: relative;
  display: flex;
  justify-content: center;
  align-items: center;
}

.about-unique-right img {
  height: clamp(260px, 45vh, 420px);
  width: auto;
  max-width: 100%;
  object-fit: contain;
  display: block;
}

/* RESPONSIVE */
@media (max-width: 900px) {
  .about-hero {
    padding: 4rem 5%;
    min-height: auto;
  }

  .about-hero-inner,
  .about-unique-inner {
    grid-template-columns: 1fr;
    text-align: center;
    gap: 2.5rem;
    margin-top: 0;
  }

  .about-hero-left p,
  .about-unique-left p {
    margin: 0 auto 1.5rem;
    max-width: 600px;
  }

  .about-hero-right,
  .about-unique-right {
    transform: none !important;
    margin: 1.5rem auto 0;
    width: 100%;
  }

  .about-unique-right img {
    position: static !important;
    left: auto !important;
    margin: 0 auto !important;
    height: clamp(220px, 38vh, 340px);
    max-width: 100%;
  }

  .about-unique {
    padding: 3rem 1.25rem;
  }

  .hero-btns a {
    display: inline-block;
    margin: 8px 6px;
  }
}

@media (max-width: 480px) {
  .about-unique {
    padding: 2.5rem 1rem;
  }

  .about-hero-inner,
  .about-unique-inner {
    gap: 1.8rem;
  }

  .hero-btns {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
  }

  .hero-btns a {
    width: 100%;
    max-width: 260px;
    margin: 0;
    text-align: center;
  }
}
</style>

<!-- ================= ABOUT LIYAS ================= -->
<section class="about-unique" id="about-cards">
  <div class="about-unique-inner">

    <div class="about-unique-left">
      <span class="about-eyebrow">About LIYAS</span>
      <h2>More Than Water.<br>A Commitment to Life.</h2>
      <p>
        At LIYAS Mineral Water, we believe water is the foundation of a
        healthy life. Every bottle carries our promise of purity,
        responsibility, and care — from sourcing to sealing.
      </p>
      <p>
        For over two decades, families and businesses have trusted LIYAS
        for safe, naturally balanced hydration delivered with consistency
        and integrity.
      </p>
    </div>

    <div class="about-unique-right">
      <img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle1.png" alt="LIYAS Bottle">
    </div>

  </div>
</section>

<?php include __DIR__ . '/trustedClient.php'; ?>
