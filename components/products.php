<?php
// Fetch categories
$categories_stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$litres_stmt = $pdo->query("SELECT DISTINCT litre FROM products WHERE status = 'active' ORDER BY litre ASC");
$available_litres = $litres_stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch products with their category name
$products_stmt = $pdo->query("
    SELECT p.*, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.category_id
    WHERE p.status = 'active'
    ORDER BY p.created_at DESC
");
$products = $products_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
/* ================= FILTER BAR ================= */
.product-filters {
  display: flex;
  gap: 1rem;
  margin-bottom: 3rem;
  flex-wrap: wrap;
  z-index: 1000 !important;
}

.product-filters input,
.product-filters select {
  padding: 12px 18px;
  border-radius: 30px;
  border: 1px solid #e2e8f0;
  font-size: 0.95rem;
  outline: none;
}

.product-filters input {
  flex: 1;
  min-width: 240px;
}

/* ================= PRODUCTS ================= */
.products-list {
  padding: 4rem 0;
}


.product-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 260px));
  gap: 2.5rem;
  justify-content: center;
}

/* CARD */
.product-card {
  background: #fff;
  border-radius: 20px;
  padding: 0.6rem 3.1rem 1rem;
  text-align: center;
  box-shadow: 0 15px 35px rgba(145, 140, 140, 0.06);
  position: relative;
}

/* SIZE BADGE */
.size-badge {
  position: absolute;
  top: 14px;
  left: 14px;
  background: #e6fbff;
  color: #0b2e4e;
  font-size: 0.7rem;
  padding: 5px 12px;
  border-radius: 20px;
  font-weight: 600;
}

/* IMAGE WRAPPER */
.product-image-wrap {
  height: 180px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 1rem;
}

.product-image-wrap img {
  height: 160px;
  width: auto;
  object-fit: contain;
}

/* INFO */
.product-info .category {
  font-size: 0.75rem;
  text-transform: uppercase;
  color: #4ad2e2;
  font-weight: 600;
}

.product-info h4 {
  font-size: 1rem;
  margin: 0.5rem 0;
}

.rating {
  font-size: 0.85rem;
  color: #fbbf24;
}

.by {
  font-size: 0.8rem;
  color: #64748b;
}

.by span {
  font-weight: 600;
}

.price-section {
  margin: 1rem 0;
}

.new-price {
  font-weight: 700;
  color: #0b2e4e;
}

.old-price {
  font-size: 0.85rem;
  text-decoration: line-through;
  color: #94a3b8;
}

.add-btn {
  background: #4ad2e2;
  color: #fff;
  border: none;
  padding: 11px 28px;
  border-radius: 30px;
  cursor: pointer;
  font-weight: 600;
}
.prod-page{
    text-decoration: none;
}
</style>

<section class="products-list">
  <div class="container">

    <!-- SEARCH & FILTER -->
    <div class="product-filters">
      <input type="text" id="searchInput" placeholder="Search bottle..." />

<select id="litreFilter">
  <option value="">All Sizes</option>
  <?php foreach ($available_litres as $l): ?>
      <option value="<?= $l ?>"><?= $l ?> L</option>
  <?php endforeach; ?>
</select>

      <select id="categoryFilter">
        <option value="">All Categories</option>
        <?php foreach ($categories as $category): ?>
            <option value="<?= strtolower(htmlspecialchars($category['name'])) ?>"><?= htmlspecialchars($category['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- PRODUCT GRID -->
    <div class="product-grid">

      <?php if (empty($products)): ?>
        <p>No products found.</p>
      <?php else: ?>
        <?php foreach ($products as $product): ?>
        <a class="prod-page" href="/products/">  <div class="product-card" 
               onclick="if(document.getElementById('productDetailModal')) { event.preventDefault(); } openProductModal(<?= $product['product_id'] ?>)"
               style="cursor: pointer;"
               data-product-id="<?= $product['product_id'] ?>"
               data-name="<?= strtolower(htmlspecialchars($product['name'])) ?>"
            data-litre="<?= htmlspecialchars($product['litre']) ?>"
 data-category="<?= strtolower(htmlspecialchars($product['category_name'])) ?>">

<div class="size-badge"><?= htmlspecialchars($product['litre']) ?> L</div>

            <div class="product-image-wrap">
              <img src="<?= BASE_URL ?>/admin/uploads/products/<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
            </div>

            <div class="product-info">
              <p class="category"><?= htmlspecialchars($product['category_name']) ?></p>
              <h4><?= htmlspecialchars($product['name']) ?></h4>
              <div class="rating">⭐⭐⭐⭐☆ <span>(4.0)</span></div>
              <p class="by">By <span>Liyas</span></p>
              <div class="price-section">
                <p class="new-price">₹<?= htmlspecialchars($product['price']) ?></p>
                <?php if ($product['discount'] > 0): ?>
                    <p class="old-price">₹<?= htmlspecialchars($product['price'] + ($product['price'] * $product['discount'] / 100)) ?></p>
                <?php endif; ?>
              </div>
              <button class="add-btn" onclick="event.stopPropagation();">Add</button>
            </div>
          </div></a>
        <?php endforeach; ?>
      <?php endif; ?>

    </div>
  </div>
</section>

<script>
const searchInput = document.getElementById("searchInput");
const litreFilter = document.getElementById("litreFilter");
const categoryFilter = document.getElementById("categoryFilter");

// ❗ IMPORTANT: select product-card but we will hide parent <a>
const products = document.querySelectorAll(".product-card");

function filterProducts() {
  const searchValue = searchInput.value.toLowerCase();
  const litreValue = litreFilter.value;
  const categoryValue = categoryFilter.value;

  products.forEach(product => {
    const name = product.dataset.name;
    const litre = product.dataset.litre;
    const category = product.dataset.category;

    const match =
      name.includes(searchValue) &&
      (!litreValue || litre === litreValue) &&
      (!categoryValue || category === categoryValue);

    // ✅ FIX: hide the parent <a> instead of card
    const wrapper = product.closest(".prod-page");
    wrapper.style.display = match ? "block" : "none";
  });
}

// Events
searchInput.addEventListener("input", filterProducts);
litreFilter.addEventListener("change", filterProducts);
categoryFilter.addEventListener("change", filterProducts);
</script>
