console.log('cart.js loaded');
document.addEventListener('DOMContentLoaded', () => {
    const cartSidebar = document.getElementById('cart-sidebar');
    const closeCartBtn = document.getElementById('close-cart-btn');
    const cartIcon = document.getElementById('cart-icon');
    const addButtons = document.querySelectorAll('.add-btn');
    const cartBody = document.querySelector('.cart-body');
    const subtotalPrice = document.getElementById('subtotal-price');

    let cart = [];

 function fetchCart() {

    fetch(BASE_URL + '/get_cart.php')
        .then(response => response.json())
        .then(data => {

            if (data.error) {
                console.log(data.error);
                return;
            }

            cart = data;

            console.log('Cart data fetched:', cart);

            renderCart();

        })
        .catch(error => console.error('Error fetching cart:', error));

}

    // --- EVENT LISTENERS ---

    // Listen for custom event from the product modal
    document.addEventListener('addToCartFromModal', function(e) {
        if (e.detail) {
            addToCart(e.detail);
            cartSidebar.classList.add('open');
        }
    });

 if (cartIcon) {

    cartIcon.addEventListener('click', (e) => {

        e.preventDefault();

        console.log('Cart icon clicked');

        if (typeof userIsLoggedIn !== 'undefined' && userIsLoggedIn) {

            cartSidebar.classList.add('open');

        } else {

            window.location.href = BASE_URL + '/login';

        }

    });

}

    if (closeCartBtn) {
        closeCartBtn.addEventListener('click', () => {
            cartSidebar.classList.remove('open');
        });
    }

    // --- Add to Cart buttons on product cards ---
    const productCardButtons = document.querySelectorAll('.product-card .add-btn');
    productCardButtons.forEach(button => {
        button.addEventListener('click', () => {
            // Check for login status (variable must be available globally)
            if (typeof userIsLoggedIn !== 'undefined' && userIsLoggedIn) {
                const card = button.closest('.product-card');
                const product = {
                    id: card.dataset.productId,
                    name: card.querySelector('h4').textContent,
                    price: parseFloat(card.querySelector('.new-price').textContent.replace('₹', '')),
                    image: card.querySelector('img').src,
                    quantity: 1
                };
                addToCart(product);
                cartSidebar.classList.add('open');
            } else {
                // User is not logged in, redirect to login page
                window.location.href = BASE_URL + '/login';
            }
        });
    });


    function addToCart(product) {
        console.log('Adding to cart:', product);
        const existingItem = cart.find(item => item.product_id == product.id); // Loose equality

        if (existingItem) {
            existingItem.quantity++;
            updateQuantity(existingItem.product_id, existingItem.quantity);
        } else {
            // Optimistically add to local cart, then sync
            const newCartItem = { ...product, product_id: product.id, quantity: 1 };
            cart.push(newCartItem);
            renderCart(); // Render immediately for better UX
            syncCart(newCartItem);
        }
    }

    function syncCart(item) {
        const formData = new FormData();
        formData.append('product_id', item.product_id);
        formData.append('quantity', item.quantity);

        fetch(BASE_URL + '/add_to_cart.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                console.error('Error syncing cart:', data.error);
                // Optionally, revert the optimistic update here
            }
        })
        .catch(error => console.error('Error syncing cart:', error));
    }
    
    function updateQuantity(productId, quantity) {
        const formData = new FormData();
        formData.append('product_id', productId);
        formData.append('quantity', quantity);

        console.log('Sending update quantity request for product:', productId, 'quantity:', quantity); // DEBUG: Before fetch call

        fetch(BASE_URL + '/update_cart_quantity.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Received response from update_cart_quantity.php', response); // DEBUG: Response received
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                console.error('Error updating quantity on server:', data.error);
            } else {
                console.log('Server response for quantity update:', data);
                if (data.success && data.affected_rows > 0) {
                    fetchCart();
                } else if (data.success && data.action === 'deleted') {
                    fetchCart();
                }
            }
        })
        .catch(error => console.error('Fetch or JSON parsing error for quantity update:', error)); // DEBUG: Catch fetch/JSON errors
    }

    function removeFromCart(productId) {
        const formData = new FormData();
        formData.append('product_id', productId);

        fetch(BASE_URL + '/remove_from_cart.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json()) // Parse the JSON response
        .then(data => {
            if (data.error) {
                console.error('Error removing item from server:', data.error);
            } else {
                console.log('Server response for item removal:', data);
                // Only refetch if the removal was successful and affected rows
                if (data.success && data.affected_rows > 0) {
                    cart = cart.filter(item => item.product_id != productId); // Loose equality
                    renderCart();
                }
            }
        })
        .catch(error => console.error('Error removing item:', error));
    }

    function renderCart() {
        cartBody.innerHTML = '';

        if (cart.length === 0) {
            cartBody.innerHTML = '<p class="cart-empty-message">Your cart is empty.</p>';
        } else {
            cart.forEach(item => {
                const cartItem = document.createElement('div');
                cartItem.className = 'cart-item';
                cartItem.innerHTML = `
                    <img src="${item.image}" alt="${item.name}" class="cart-item-img">
                    <div class="cart-item-details">
                        <h5 class="cart-item-title">${item.name}</h5>
                        <p class="cart-item-price">₹${(parseFloat(item.price) * item.quantity).toFixed(2)}</p>
                        <div class="cart-item-quantity">
                            <button class="quantity-btn minus-btn" data-id="${item.product_id}">-</button>
                            <span class="item-quantity">${item.quantity}</span>
                            <button class="quantity-btn plus-btn" data-id="${item.product_id}">+</button>
                            <button class="remove-btn" data-id="${item.product_id}"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </div>
                `;
                cartBody.appendChild(cartItem);
            });
        }
        updateSubtotal();
    }

    function updateSubtotal() {
        const subtotal = cart.reduce((total, item) => total + (item.price * item.quantity), 0);
        subtotalPrice.textContent = `₹${subtotal.toFixed(2)}`;
    }
    
    // Use event delegation for quantity and remove buttons
    if (cartBody) {

    cartBody.addEventListener('click', (e) => {

        const target = e.target;
        let button = target;

        if (target.tagName === 'I' && target.closest('button')) {
            button = target.closest('button');
        }

        if (button.classList.contains('quantity-btn')) {

            const id = button.dataset.id;

            const item = cart.find(item => item.product_id == id);

            if (item) {

                if (button.classList.contains('minus-btn')) {
                    updateQuantity(id, item.quantity - 1);
                } else if (button.classList.contains('plus-btn')) {
                    updateQuantity(id, item.quantity + 1);
                }

            }

        } else if (button.classList.contains('remove-btn')) {

            const id = button.dataset.id;

            const item = cart.find(item => item.product_id == id);

            if (item) {
                removeFromCart(id);
            }

        }

    });

}
        const target = e.target;
        let button = target;

        // If the target is an icon inside a button, get the parent button
        if (target.tagName === 'I' && target.closest('button')) {
            button = target.closest('button');
        }

        if (button.classList.contains('quantity-btn')) {
            const id = button.dataset.id;
            console.log('Clicked quantity button:', button, 'ID:', id, 'Classes:', button.classList); // DEBUG
            console.log('Comparing:', { idFromButton: id, typeIdFromButton: typeof id, cartItems: cart.map(item => ({ productId: item.product_id, typeProductId: typeof item.product_id })) }); // DEBUG: Log comparison types and values
            const item = cart.find(item => item.product_id == id); // Use loose equality for now
            if (item) {
                if (button.classList.contains('minus-btn')) {
                    updateQuantity(id, item.quantity - 1);
                } else if (button.classList.contains('plus-btn')) {
                    updateQuantity(id, item.quantity + 1);
                }
            } else {
                console.warn('Item not found in local cart (quantity update):', id); // DEBUG: Item not found
            }
        } else if (button.classList.contains('remove-btn')) {
            const id = button.dataset.id;
            console.log('Clicked remove button:', button, 'ID:', id, 'Classes:', button.classList); // DEBUG
            console.log('Comparing for removal:', { idFromButton: id, typeIdFromButton: typeof id, cartItems: cart.map(item => ({ productId: item.product_id, typeProductId: typeof item.product_id })) }); // DEBUG: Log comparison types and values
            const item = cart.find(item => item.product_id == id); // Use loose equality for now
            if (item) {
                removeFromCart(id);
            } else {
                console.warn('Item not found in local cart (removal):', id); // DEBUG: Item not found for removal
            }
        }
    });

    // Initial fetch
    fetchCart();
});