<?php
require_once 'includes/auth.php';
startSession();

// Redirect if already logged in
if (isLoggedIn()) {
    $role = $_SESSION['user_role'] ?? 'patient';
    header('Location: dashboard.php');
    exit;
}

$products = [];
$db = getDB();
$result = $db->query("SELECT p.*, c.name as category_name, i.quantity FROM products p LEFT JOIN categories c ON p.category_id = c.id LEFT JOIN inventory i ON p.id = i.product_id WHERE p.status='active' LIMIT 8");
while ($row = $result->fetch_assoc()) $products[] = $row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RS Pharmacy - Your Trusted Health Partner</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { margin-left: 0 !important; }
        .services-section { background: var(--white); padding: 72px 24px; }
        .section-header { text-align: center; margin-bottom: 48px; }
        .section-title { font-family: var(--font-display); font-size: 36px; font-weight: 700; color: var(--black); }
        .section-sub { font-size: 15px; color: var(--gray); margin-top: 8px; }
        .services-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; max-width: 960px; margin: 0 auto; }
        .service-card { padding: 28px; border-radius: var(--radius); border: 1.5px solid var(--gray-ultra); transition: var(--transition); }
        .service-card:hover { border-color: var(--teal); box-shadow: var(--shadow); }
        .service-card.featured { background: var(--dark); border-color: var(--dark); }
        .service-card.featured .service-title,
        .service-card.featured .service-desc,
        .service-card.featured .service-link { color: var(--white); }
        .service-icon { width: 44px; height: 44px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 20px; margin-bottom: 16px; }
        .service-card:not(.featured) .service-icon { background: rgba(0,137,123,0.1); color: var(--teal); }
        .service-card.featured .service-icon { background: rgba(255,255,255,0.1); color: var(--white); }
        .service-title { font-weight: 700; font-size: 15px; margin-bottom: 8px; }
        .service-desc { font-size: 13px; color: var(--gray); line-height: 1.6; margin-bottom: 12px; }
        .service-link { font-size: 12px; font-weight: 600; color: var(--teal); text-decoration: none; }
        .products-section { background: var(--gray-ultra); padding: 72px 24px; }
        .products-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; max-width: 1100px; margin: 0 auto; }
        .product-card { background: var(--white); border-radius: var(--radius); padding: 20px; box-shadow: var(--shadow); transition: var(--transition); }
        .product-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
        .product-img { height: 80px; display: flex; align-items: center; justify-content: center; background: var(--gray-ultra); border-radius: var(--radius-sm); margin-bottom: 14px; font-size: 32px; }
        .product-category { font-size: 11px; color: var(--teal); font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; }
        .product-name { font-size: 14px; font-weight: 700; margin: 4px 0; }
        .product-price { font-size: 18px; font-weight: 700; color: var(--primary); }
        .product-badge { display: inline-block; background: rgba(192,57,43,0.1); color: var(--primary); font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 20px; margin-bottom: 8px; }
        .footer-section { background: var(--dark); padding: 48px 24px 24px; }
        .footer-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 40px; max-width: 1100px; margin: 0 auto 40px; }
        .footer-brand p { color: rgba(255,255,255,0.4); font-size: 13px; margin-top: 12px; line-height: 1.7; }
        .footer-col h4 { font-size: 13px; font-weight: 700; color: var(--white); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 16px; }
        .footer-col a { display: block; font-size: 13px; color: rgba(255,255,255,0.4); text-decoration: none; margin-bottom: 8px; transition: var(--transition); }
        .footer-col a:hover { color: var(--white); }
        .footer-bottom { border-top: 1px solid rgba(255,255,255,0.08); padding-top: 20px; text-align: center; font-size: 12px; color: rgba(255,255,255,0.3); max-width: 1100px; margin: 0 auto; }
        @media (max-width: 768px) { .services-grid { grid-template-columns: 1fr; } .products-grid { grid-template-columns: 1fr 1fr; } .footer-grid { grid-template-columns: 1fr 1fr; } .hero h1 { font-size: 34px; } }
    </style>
</head>
<body>

<!-- Navigation -->
<nav class="landing-nav">
    <a href="index.php" class="brand">
        <i class="fas fa-pills"></i>
        <span class="brand-label">RS Pharmacy</span>
    </a>
    <div class="landing-links">
        <a href="#services">Services</a>
        <a href="#products">Products</a>
        <a href="#contact">Contact</a>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <a href="login.php" class="btn btn-outline btn-sm">Sign In</a>
        <a href="login.php?tab=register" class="btn btn-primary btn-sm">Get Started</a>
    </div>
</nav>

<!-- Announcement Bar -->
<div style="background:var(--primary);color:white;text-align:center;padding:8px;font-size:12px;font-weight:600;">
    <i class="fas fa-shield-check"></i>&nbsp; FDA Registered Pharmacy — Quality Medicines Guaranteed
</div>

<!-- Hero -->
<section class="hero">
    <div class="hero-content">
        <div class="hero-badge"><i class="fas fa-certificate"></i> FDA Registered & Licensed</div>
        <h1>Your Health, Our <em>Priority</em></h1>
        <p>Complete pharmacy and clinic management — medicines, prescriptions, appointments, and more, all in one trusted platform.</p>
        <div class="hero-btns">
            <a href="login.php" class="btn btn-primary btn-lg"><i class="fas fa-shopping-bag"></i> Shop Now</a>
            <a href="#services" class="btn btn-outline btn-lg" style="color:white;border-color:rgba(255,255,255,0.3);">Learn More</a>
        </div>
        <div class="hero-stats">
            <div class="hero-stat"><div class="hero-stat-num">500+</div><div class="hero-stat-label">Products</div></div>
            <div class="hero-stat"><div class="hero-stat-num">8K+</div><div class="hero-stat-label">Customers</div></div>
            <div class="hero-stat"><div class="hero-stat-num">10 Yrs</div><div class="hero-stat-label">Trusted</div></div>
        </div>
    </div>
</section>

<!-- Services -->
<section class="services-section" id="services">
    <div class="section-header">
        <div class="section-title">Our Services</div>
        <div class="section-sub">Comprehensive pharmacy and clinic services tailored to your needs</div>
    </div>
    <div class="services-grid">
        <div class="service-card">
            <div class="service-icon"><i class="fas fa-shopping-cart"></i></div>
            <div class="service-title">Online Ordering</div>
            <div class="service-desc">Browse and order medicines from the comfort of your home with fast delivery.</div>
            <a href="login.php" class="service-link">Shop Now →</a>
        </div>
        <div class="service-card">
            <div class="service-icon"><i class="fas fa-user-md"></i></div>
            <div class="service-title">Doctor Consultation</div>
            <div class="service-desc">Book appointments with licensed doctors for professional medical advice.</div>
            <a href="login.php" class="service-link">Book Now →</a>
        </div>
        <div class="service-card featured">
            <div class="service-icon"><i class="fas fa-file-prescription"></i></div>
            <div class="service-title">Prescription Management</div>
            <div class="service-desc">Upload and manage your prescriptions securely with our pharmacists.</div>
            <a href="login.php" class="service-link">Get Started →</a>
        </div>
        <div class="service-card">
            <div class="service-icon"><i class="fas fa-bookmark"></i></div>
            <div class="service-title">Medication Reservation</div>
            <div class="service-desc">Reserve your medicines in advance to ensure availability when you need them.</div>
            <a href="login.php" class="service-link">Reserve →</a>
        </div>
        <div class="service-card">
            <div class="service-icon"><i class="fas fa-syringe"></i></div>
            <div class="service-title">Vaccination Services</div>
            <div class="service-desc">Schedule and track vaccinations for you and your family.</div>
            <a href="login.php" class="service-link">Schedule →</a>
        </div>
        <div class="service-card">
            <div class="service-icon"><i class="fas fa-heartbeat"></i></div>
            <div class="service-title">Health Monitoring</div>
            <div class="service-desc">Track health metrics and receive personalized wellness recommendations.</div>
            <a href="login.php" class="service-link">Monitor →</a>
        </div>
    </div>
</section>

<!-- Products -->
<section class="products-section" id="products">
    <div class="section-header">
        <div class="section-title">Featured Products</div>
        <div class="section-sub">Quality medicines and healthcare products available in store</div>
    </div>
    <div class="products-grid">
        <?php
        $icons = ['Medicines' => '💊', 'Vitamins & Supplements' => '🧃', 'Personal Care' => '🧴', 'Medical Supplies' => '🩺', 'First Aid' => '🩹'];
        foreach ($products as $p):
            $icon = $icons[$p['category_name'] ?? ''] ?? '💊';
        ?>
        <div class="product-card">
            <div class="product-img"><?= $icon ?></div>
            <?php if ($p['product_type'] === 'OTC'): ?><span class="product-badge">OTC</span><?php else: ?><span class="product-badge" style="background:rgba(0,137,123,0.1);color:var(--teal);">Rx</span><?php endif; ?>
            <div class="product-category"><?= sanitize($p['category_name'] ?? '') ?></div>
            <div class="product-name"><?= sanitize($p['name']) ?></div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;">
                <div class="product-price"><?= formatCurrency($p['price']) ?></div>
                <a href="login.php" class="btn btn-teal btn-sm"><i class="fas fa-cart-plus"></i></a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="text-align:center;margin-top:32px;">
        <a href="login.php" class="btn btn-dark btn-lg">View All Products <i class="fas fa-arrow-right"></i></a>
    </div>
</section>

<!-- Contact -->
<section style="background:var(--white);padding:72px 24px;" id="contact">
    <div style="max-width:960px;margin:0 auto;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center;">
            <div>
                <div class="section-title" style="text-align:left;">Visit or Reach Out</div>
                <div style="margin-top:24px;display:flex;flex-direction:column;gap:16px;">
                    <div style="display:flex;gap:16px;align-items:center;">
                        <div style="width:44px;height:44px;background:rgba(192,57,43,0.1);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--primary);flex-shrink:0;"><i class="fas fa-map-pin"></i></div>
                        <div><div style="font-weight:600;font-size:14px;">Address</div><div style="font-size:13px;color:var(--gray);">Labasan, Bongabong, Oriental Mindoro</div></div>
                    </div>
                    <div style="display:flex;gap:16px;align-items:center;">
                        <div style="width:44px;height:44px;background:rgba(0,137,123,0.1);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--teal);flex-shrink:0;"><i class="fas fa-phone"></i></div>
                        <div><div style="font-weight:600;font-size:14px;">Phone</div><div style="font-size:13px;color:var(--gray);">+63 917 123 4567</div></div>
                    </div>
                    <div style="display:flex;gap:16px;align-items:center;">
                        <div style="width:44px;height:44px;background:rgba(10,132,255,0.1);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--info);flex-shrink:0;"><i class="fas fa-envelope"></i></div>
                        <div><div style="font-weight:600;font-size:14px;">Email</div><div style="font-size:13px;color:var(--gray);">info@rspharmacy.com</div></div>
                    </div>
                </div>
            </div>
            <div style="background:var(--gray-ultra);border-radius:var(--radius);padding:28px;">
                <div style="font-weight:700;font-size:15px;margin-bottom:16px;">Operating Hours</div>
                <?php
                $hours = [['Mon–Fri', '8:00 AM – 8:00 PM', 'open'], ['Saturday', '8:00 AM – 6:00 PM', 'open'], ['Sunday', '9:00 AM – 4:00 PM', 'limited'], ['Holidays', 'Emergency only', 'limited']];
                foreach ($hours as [$day, $time, $status]):
                ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--gray-light);">
                    <span style="font-size:13px;font-weight:500;"><?= $day ?></span>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:12px;color:var(--gray);"><?= $time ?></span>
                        <span class="badge <?= $status === 'open' ? 'badge-success' : 'badge-warning' ?>"><?= ucfirst($status) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- CTA Banner -->
<div style="background:var(--primary);padding:56px 24px;text-align:center;">
    <h2 style="font-family:var(--font-display);font-size:34px;color:white;margin-bottom:12px;">Ready to Take Control of Your Health?</h2>
    <p style="color:rgba(255,255,255,0.75);font-size:15px;margin-bottom:24px;">Join thousands of customers who trust RS Pharmacy for their healthcare needs.</p>
    <a href="login.php?tab=register" class="btn btn-dark btn-lg">Create Free Account <i class="fas fa-arrow-right"></i></a>
</div>

<!-- Footer -->
<footer class="footer-section">
    <div class="footer-grid">
        <div class="footer-brand">
            <div style="display:flex;align-items:center;gap:10px;"><i class="fas fa-pills" style="font-size:22px;color:var(--primary);"></i><span style="font-size:18px;font-weight:700;color:white;">RS Pharmacy</span></div>
            <p>Trusted pharmacy and clinic management for modern healthcare needs in Oriental Mindoro.</p>
        </div>
        <div class="footer-col">
            <h4>Services</h4>
            <a href="#">Online Ordering</a>
            <a href="#">Prescriptions</a>
            <a href="#">Appointments</a>
            <a href="#">Vaccination</a>
        </div>
        <div class="footer-col">
            <h4>Company</h4>
            <a href="#">About Us</a>
            <a href="#">Mission & Vision</a>
            <a href="#">Contact</a>
        </div>
        <div class="footer-col">
            <h4>Legal</h4>
            <a href="#">Privacy Policy</a>
            <a href="#">Terms of Service</a>
            <a href="#">Cookie Policy</a>
        </div>
    </div>
    <div class="footer-bottom">© 2026 RS Pharmacy. Mindoro State University — BSIT Capstone Project. All rights reserved.</div>
</footer>

<script src="assets/js/main.js"></script>
</body>
</html>
