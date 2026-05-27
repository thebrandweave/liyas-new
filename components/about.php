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
  font-size: clamp(3rem, 8vw, 5.5rem);
  font-weight: 900;
  letter-spacing: 0.18em;
  color: #0b2e4e;
  margin-bottom: 0.3rem;
}

.brand-subtitle {
  font-size: clamp(1.8rem, 4vw, 2.8rem);
  font-weight: 700;
  margin-bottom: 1.2rem;
}

.brand-subtitle span {
  color: #4ad2e2;
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
  background: #4ad2e2;
  color: #fff;
}

.btn-primary:hover {
  background: #2cbac9;
}

.btn-outline {
  border: 2px solid #4ad2e2;
  color: #4ad2e2;
}

.btn-outline:hover {
  background: #4ad2e2;
  color: #fff;
}

.about-hero-right {
  display: flex;
  justify-content: center;
  align-items: center;
}

.about-hero-right img {
  width: 280px;
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
  padding: 0rem 8%;
  background: linear-gradient(180deg, #ffffff 0%, #f3fdff 100%);
  overflow: hidden;
}

.about-unique-inner {
  display: grid;
  grid-template-columns: 1.2fr 1fr;
  align-items: center;
  gap: 4rem;
  max-width: 1200px;
  margin: auto;
  margin-top: 100px;
}

.about-eyebrow {
  font-size: 0.85rem;
  color: #4ad2e2;
  font-weight: 600;
  letter-spacing: 1px;
  text-transform: uppercase;
}

.about-unique-left h2 {
  font-size: clamp(2rem, 4vw, 2.6rem);
  font-weight: 700;
  margin: 1rem 0;
  color: #0b2e4e;
}

.about-unique-left p {
  color: #5a6b7b;
  font-size: 1rem;
  margin-bottom: 1.2rem;
  max-width: 520px;
}

.about-unique-right {
  position: relative;
  display: flex;
  justify-content: center;
  align-items: center;
  transform: translateX(40px);
}

.about-unique-right img {
  /* width: 320px;
  z-index: 2;
  max-width: none; */
  position:relative;
  left: 135px;
  height: 45vh;
}

/*.vertical-text {*/
/*  position: absolute;*/
/*  font-size: 130px;*/
/*  font-weight: 800;*/
/*  color: rgba(74, 210, 226, 0.12);*/
/*  letter-spacing: 12px;*/
/*  transform: rotate(-90deg);*/
/*  left: 20px;*/
/*}*/
/* ================= PREMIUM HERO UPGRADE ================= */

.about-hero {
  min-height: 90vh;
  background: radial-gradient(circle at top left, #f4fdff, #e8fbff 40%, #ffffff 75%);
  display: flex;
  align-items: center;
  padding: 8rem 8%;
}

.about-hero-inner {
  display: grid;
  grid-template-columns: 1.1fr 1fr;
  align-items: center;
  gap: 4rem;
  max-width: 1200px;
  margin: auto;
}

.hero-desc {
  font-size: 1.05rem;
  color: #5a6b7b;
  max-width: 480px;
  margin-bottom: 1.8rem;
}

.hero-meta {
  font-size: 0.85rem;
  letter-spacing: 1px;
  color: #0b2e4e;
  opacity: 0.7;
}

/* PRODUCT PEDESTAL */
.product-pedestal {
  position: relative;
  width: 320px;
  height: 320px;
  border-radius: 50%;
  background: linear-gradient(145deg, #ffffff, #e6f8fc);
  box-shadow:
    0 40px 80px rgba(11, 46, 78, 0.15),
    inset 0 0 40px rgba(74, 210, 226, 0.25);
  display: flex;
  align-items: center;
  justify-content: center;
}

.product-pedestal img {
  width: 160px;
  z-index: 2;
  animation: floatBottle 6s ease-in-out infinite;
}

/* Glow ring */
.product-pedestal::after {
  content: '';
  position: absolute;
  inset: -15px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(74,210,226,0.25), transparent 70%);
  z-index: 1;
}

/* RESPONSIVE */
@media (max-width: 900px) {
  .about-hero-inner {
    grid-template-columns: 1fr;
    text-align: center;
  }

  .hero-desc {
    margin: 0 auto 1.8rem;
  }

  .about-hero-right {
    margin-top: 3rem;
  }
}

/* ================= RESPONSIVE ================= */
@media (max-width: 900px) {
  .about-hero-inner,
  .about-unique-inner {
    grid-template-columns: 1fr;
    text-align: center;
  }

  .about-hero-left p {
    margin: auto auto 2rem;
  }

  /*.vertical-text {*/
  /*  display: none;*/
  /*}*/

  .hero-btns a {
    display: block;
    margin: 10px auto;
  }
}

@media (max-width: 499px){
    
    .about-unique-right img {

  margin-right:350px;
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
      <!--<div class="vertical-text">LIYAS</div>-->
      <img src="<?php echo $asset_base; ?>../assets/images/liyas-bottle.png" alt="LIYAS Bottle">
    </div>

  </div>
</section>

<?php include __DIR__ . '/trustedClient.php'; ?>
