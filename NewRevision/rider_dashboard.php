<?php
session_start();

// Redirect to login if the rider is not logged in
if (!isset($_SESSION['RiderID'])) {
    header("Location: rider_login.php");
    exit();
}

// Database connection
$conn = new mysqli('localhost', 'root', '', 'procook');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch rider details
$riderID = $_SESSION['RiderID'];
$sql = "SELECT * FROM DeliveryPersonnel WHERE RiderID = $riderID";
$result = $conn->query($sql);
$rider = $result->fetch_assoc();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $fullName = $conn->real_escape_string($_POST['fullName']);
    $email = $conn->real_escape_string($_POST['email']);
    $phoneNumber = $conn->real_escape_string($_POST['phoneNumber']);
    $address = $conn->real_escape_string($_POST['address']);
    $licenseNumber = $conn->real_escape_string($_POST['licenseNumber']);

    // Handle profile picture upload
    $profilePicture = $rider['ProfilePicture']; // Default to existing picture
    if ($_FILES['profilePicture']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/profile_pictures/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true); // Create directory if it doesn't exist
        }
        $uploadFile = $uploadDir . basename($_FILES['profilePicture']['name']);
        if (move_uploaded_file($_FILES['profilePicture']['tmp_name'], $uploadFile)) {
            $profilePicture = $uploadFile;
        }
    }

    // Handle ID Proof upload
    $idProof = $rider['IDProof']; // Default to existing ID Proof
    if ($_FILES['idProof']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/id_proofs/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true); // Create directory if it doesn't exist
        }
        $uploadFile = $uploadDir . basename($_FILES['idProof']['name']);
        if (move_uploaded_file($_FILES['idProof']['tmp_name'], $uploadFile)) {
            $idProof = $uploadFile;
        }
    }

    // Update profile
    $updateSql = "UPDATE DeliveryPersonnel SET FullName = '$fullName', Email = '$email', PhoneNumber = '$phoneNumber', Address = '$address', LicenseNumber = '$licenseNumber', ProfilePicture = '$profilePicture', IDProof = '$idProof' WHERE RiderID = $riderID";

    if ($conn->query($updateSql) === TRUE) {
        $_SESSION['successMessage'] = "Profile updated successfully!";
        header("Location: rider_dashboard.php");
        exit();
    } else {
        $_SESSION['errorMessage'] = "Error updating profile: " . $conn->error;
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['currentPassword'];
    $newPassword = $_POST['newPassword'];
    $confirmNewPassword = $_POST['confirmNewPassword'];

    if (password_verify($currentPassword, $rider['Password'])) {
        if ($newPassword === $confirmNewPassword) {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updatePasswordSql = "UPDATE DeliveryPersonnel SET Password = '$hashedPassword' WHERE RiderID = $riderID";

            if ($conn->query($updatePasswordSql)) {
                $_SESSION['successMessage'] = "Password updated successfully!";
                header("Location: rider_dashboard.php");
                exit();
            } else {
                $_SESSION['errorMessage'] = "Error updating password: " . $conn->error;
            }
        } else {
            $_SESSION['errorMessage'] = "New passwords do not match.";
        }
    } else {
        $_SESSION['errorMessage'] = "Current password is incorrect.";
    }
}

// Handle Pick Up Order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pick_up_order'])) {
    $orderID = (int)$_POST['orderID'];

    // Check if the order is still available (not picked up by another rider)
    $checkOrderQuery = "SELECT Status FROM Checkout WHERE OrderID = $orderID AND Status = 'Waiting for a Courier'";
    $checkOrderResult = $conn->query($checkOrderQuery);

    if ($checkOrderResult->num_rows > 0) {
        // Update the order status to "Out for Delivery" and assign the rider
        $updateSql = "UPDATE Checkout SET Status = 'Out for Delivery', RiderID = $riderID WHERE OrderID = $orderID";

        if ($conn->query($updateSql)) {
            $_SESSION['successMessage'] = "Order #$orderID has been picked up.";
        } else {
            $_SESSION['errorMessage'] = "Error picking up order: " . $conn->error;
        }
    } else {
        $_SESSION['errorMessage'] = "Order #$orderID is no longer available.";
    }

    // Redirect to prevent form resubmission
    header("Location: rider_dashboard.php");
    exit();
}

// Handle Mark as Delivered
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_delivered'])) {
    $orderID = (int)$_POST['orderID'];

    // Update the order status to "Delivered"
    $updateSql = "UPDATE Checkout SET Status = 'Delivered' WHERE OrderID = $orderID AND Status = 'Out for Delivery' AND RiderID = $riderID";

    if ($conn->query($updateSql)) {
        $_SESSION['successMessage'] = "Order #$orderID has been delivered.";
    } else {
        $_SESSION['errorMessage'] = "Error marking order as delivered: " . $conn->error;
    }

    // Redirect to prevent form resubmission
    header("Location: rider_dashboard.php");
    exit();
}

// Fetch active deliveries assigned to the rider with restaurant and customer details
$activeDeliveriesQuery = "
    SELECT 
        Checkout.*, 
        BusinessProfile.RestaurantName, 
        BusinessProfile.RestaurantAddress, 
        Customers.Name AS CustomerName, 
        Customers.Address AS CustomerAddress 
    FROM Checkout 
    LEFT JOIN BusinessProfile ON Checkout.BusinessID = BusinessProfile.BusinessID 
    LEFT JOIN Customers ON Checkout.CustomerID = Customers.CustomerID 
    WHERE (Checkout.Status = 'Waiting for a Courier' OR Checkout.Status = 'Out for Delivery') 
    ORDER BY Checkout.OrderTimestamp DESC
";
$activeDeliveriesResult = $conn->query($activeDeliveriesQuery);

$activeDeliveries = [];
while ($order = $activeDeliveriesResult->fetch_assoc()) {
    $activeDeliveries[] = $order;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ProCook - DeliveryPersonnel Dashboard</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="riderStyles.css">
    <style>
        .star {
            cursor: pointer;
            color: gold;
            font-size: 20px;
        }
        .star:hover {
            color: orange;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div>
            <h2>ProCook</h2>
            <ul>
                <li><a href="#" id="dashboard-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="#" id="active-deliveries-link"><i class="fas fa-truck"></i> Active Deliveries</a></li>
                <li><a href="#" id="delivery-history-link"><i class="fas fa-history"></i> Delivery History</a></li>
                <li><a href="#" id="earnings-link"><i class="fas fa-money-bill-wave"></i> Earnings</a></li>
                <li><a href="#" id="profile-link"><i class="fas fa-user"></i> Profile</a></li>
                <li><a href="#" id="notifications-link"><i class="fas fa-bell"></i> Notifications</a></li>
            </ul>
        </div>
        <button class="logout-button" onclick="window.location.href='logout.php'">Logout</button>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div id="dashboard" class="content-section active">
            <h1>Dashboard</h1>
            <p>Welcome to your dashboard, <?php echo htmlspecialchars($rider['FullName']); ?>!</p>
        </div>
        <!-- Active Deliveries Content -->
        <div id="active-deliveries" class="content-section active">
            <h1>Active Deliveries</h1>
            <?php if (!empty($activeDeliveries)): ?>
                <div class="orders-list">
                    <?php foreach ($activeDeliveries as $order): ?>
                        <div class="order-item">
                            <!-- Order Header -->
                            <div class="order-header">
                                <div class="customer-info">
                                    <h3>Order ID: <?php echo $order['OrderID']; ?></h3>
                                    <p>Status: <?php echo $order['Status']; ?></p>
                                </div>
                            </div>

                            <!-- Order Details -->
                            <div class="order-details">
                                <p><strong>Restaurant Name:</strong> <?php echo $order['RestaurantName']; ?></p>
                                <p><strong>Restaurant Address:</strong> <?php echo $order['RestaurantAddress']; ?></p>
                                <p><strong>Customer Name:</strong> <?php echo $order['CustomerName']; ?></p>
                                <p><strong>Customer Address:</strong> <?php echo $order['CustomerAddress']; ?></p>
                                <p><strong>Total Price:</strong> ₱<?php echo $order['TotalPrice']; ?></p>
                                <p><strong>Delivery Option:</strong> <?php echo $order['DeliveryOption']; ?></p>
                                <p><strong>Payment Method:</strong> <?php echo $order['PaymentMethod']; ?></p>
                                <p><strong>Order Timestamp:</strong> <?php echo $order['OrderTimestamp']; ?></p>
                                <p><strong>Estimated Delivery Time:</strong> <?php echo $order['EstimatedDeliveryTime']; ?></p>
                                <p><strong>Distance:</strong> <?php echo $order['Distance']; ?> km</p>
                                <p><strong>Delivery Fee:</strong> ₱<?php echo $order['DeliveryFee']; ?></p>
                            </div>

                            <!-- Order Actions -->
                            <div class="order-actions">
                                <?php if ($order['Status'] === 'Waiting for a Courier'): ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="orderID" value="<?php echo $order['OrderID']; ?>">
                                        <button type="submit" name="pick_up_order" class="btn-action">Pick Up Order</button>
                                    </form>
                                <?php elseif ($order['Status'] === 'Out for Delivery' && $order['RiderID'] == $riderID): ?>
                                    <form method="POST" action="">
                                        <input type="hidden" name="orderID" value="<?php echo $order['OrderID']; ?>">
                                        <button type="submit" name="mark_delivered" class="btn-action">Mark as Delivered</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>No active deliveries found.</p>
            <?php endif; ?>
        </div>

        <!-- Profile Content -->
        <div id="profile" class="content-section">
            <h1>Profile</h1>
            <div class="profile-content">
                <div class="profile-form-container">
                    <!-- Left Column: Personal Information -->
                    <div class="profile-form-left">
                        <!-- Personal Information -->
                        <form method="POST" action="" enctype="multipart/form-data">
                            <div class="profile-form-section">
                                <h2>Personal Information</h2>
                                <div class="form-group">
                                    <label for="fullName">Full Name:</label>
                                    <input type="text" id="fullName" name="fullName" value="<?php echo $rider['FullName']; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="email">Email:</label>
                                    <input type="email" id="email" name="email" value="<?php echo $rider['Email']; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="phoneNumber">Phone Number:</label>
                                    <input type="text" id="phoneNumber" name="phoneNumber" value="<?php echo $rider['PhoneNumber']; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="address">Address:</label>
                                    <input type="text" id="address" name="address" value="<?php echo $rider['Address']; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="licenseNumber">License Number:</label>
                                    <input type="text" id="licenseNumber" name="licenseNumber" value="<?php echo $rider['LicenseNumber']; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="profilePicture">Profile Picture:</label>
                                    <input type="file" id="profilePicture" name="profilePicture">
                                </div>
                                <div class="form-group">
                                    <label for="idProof">ID Proof:</label>
                                    <input type="file" id="idProof" name="idProof">
                                </div>
                                <div class="form-group">
                                    <label for="deliveryVerificationStatus">Verification Status:</label>
                                    <input type="text" id="deliveryVerificationStatus" name="deliveryVerificationStatus" value="<?php echo $rider['DeliveryVerificationStatus']; ?>" readonly>
                                </div>
                            </div>

                            <!-- Update Profile Button -->
                            <button type="submit" name="update_profile" class="btn-primary">Update Profile</button>
                        </form>
                    </div>

                    <!-- Right Column: Change Password -->
                    <div class="profile-form-right">
                        <form method="POST" action="">
                            <div class="profile-form-section">
                                <h2>Change Password</h2>
                                <div class="form-group">
                                    <label for="currentPassword">Current Password:</label>
                                    <input type="password" id="currentPassword" name="currentPassword" required>
                                </div>
                                <div class="form-group">
                                    <label for="newPassword">New Password:</label>
                                    <input type="password" id="newPassword" name="newPassword" required>
                                </div>
                                <div class="form-group">
                                    <label for="confirmNewPassword">Confirm New Password:</label>
                                    <input type="password" id="confirmNewPassword" name="confirmNewPassword" required>
                                </div>
                                <button type="submit" name="change_password" class="btn-primary">Change Password</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript for Toggling Content Sections -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const links = document.querySelectorAll('.sidebar ul li a');
            const contentSections = document.querySelectorAll('.content-section');

            // Function to show the selected content section
            function showContentSection(target) {
                contentSections.forEach(section => {
                    if (section.id === target) {
                        section.classList.add('active');
                    } else {
                        section.classList.remove('active');
                    }
                });
            }

            // Add click event listeners to sidebar links
            links.forEach(link => {
                link.addEventListener('click', function (e) {
                    e.preventDefault();

                    // Remove active class from all links
                    links.forEach(l => l.classList.remove('active'));

                    // Add active class to the clicked link
                    this.classList.add('active');

                    // Show the corresponding content section
                    const target = this.getAttribute('id').replace('-link', '');
                    showContentSection(target);
                });
            });

            // Show the dashboard by default
            document.getElementById('dashboard-link').click();
        });
    </script>
</body>
</html>
