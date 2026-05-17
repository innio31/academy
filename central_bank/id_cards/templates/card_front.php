<?php
// /central_bank/id_cards/templates/card_front.php - Front side of ID card

function renderCardFront($student, $school, $settings, $qr_code_url)
{
    $primary = $settings['primary_color'];
    $secondary = $settings['secondary_color'];
    $show_motto = $settings['show_motto'];
    $show_qr = $settings['show_qr'];

    // Get profile picture (try to use existing or default)
    $profile_pic = !empty($student['profile_picture']) ? $student['profile_picture'] : 'default-avatar.png';

    ob_start();
?>
    <div class="id-card-front" style="
        width: 85.6mm;
        height: 54mm;
        background: linear-gradient(135deg, <?php echo $primary; ?> 0%, <?php echo $secondary; ?> 100%);
        border-radius: 12px;
        padding: 4mm;
        position: relative;
        overflow: hidden;
        font-family: 'Segoe UI', Arial, sans-serif;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    ">
        <!-- Background pattern -->
        <div style="position: absolute; top: -30%; right: -20%; width: 150px; height: 150px; background: rgba(255,255,255,0.05); border-radius: 50%;"></div>
        <div style="position: absolute; bottom: -20%; left: -10%; width: 100px; height: 100px; background: rgba(255,255,255,0.05); border-radius: 50%;"></div>

        <!-- School Logo & Name -->
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
            <?php if (!empty($school['logo_path']) && file_exists('../..' . $school['logo_path'])): ?>
                <img src="../..<?php echo htmlspecialchars($school['logo_path']); ?>" style="width: 35px; height: 35px; object-fit: contain; border-radius: 8px;">
            <?php else: ?>
                <div style="width: 35px; height: 35px; background: white; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                    <span style="font-size: 20px;">🎓</span>
                </div>
            <?php endif; ?>
            <div>
                <h3 style="color: white; font-size: 12px; margin: 0; font-weight: 700;"><?php echo htmlspecialchars($school['school_name']); ?></h3>
                <?php if ($show_motto && !empty($school['motto'])): ?>
                    <p style="color: rgba(255,255,255,0.8); font-size: 7px; margin: 2px 0 0;"><?php echo htmlspecialchars($school['motto']); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main Content -->
        <div style="display: flex; gap: 10px; margin-top: 5px;">
            <!-- Student Photo -->
            <div style="
                width: 35mm;
                height: 35mm;
                background: white;
                border-radius: 10px;
                overflow: hidden;
                border: 2px solid white;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                display: flex;
                align-items: center;
                justify-content: center;
            ">
                <?php if (!empty($student['profile_picture']) && file_exists('../..' . $student['profile_picture'])): ?>
                    <img src="../..<?php echo htmlspecialchars($student['profile_picture']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                <?php else: ?>
                    <div style="text-align: center; color: #999;">
                        <i class="fas fa-user-graduate" style="font-size: 28px;"></i>
                        <p style="font-size: 8px; margin: 0;">No Photo</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Student Details -->
            <div style="flex: 1; color: white;">
                <div style="margin-bottom: 6px;">
                    <p style="font-size: 7px; opacity: 0.7; margin: 0;">Student Name</p>
                    <p style="font-size: 11px; font-weight: 600; margin: 2px 0;"><?php echo htmlspecialchars($student['full_name']); ?></p>
                </div>
                <div style="margin-bottom: 6px;">
                    <p style="font-size: 7px; opacity: 0.7; margin: 0;">Admission No.</p>
                    <p style="font-size: 10px; font-weight: 500; margin: 2px 0;"><?php echo htmlspecialchars($student['admission_number']); ?></p>
                </div>
                <div>
                    <p style="font-size: 7px; opacity: 0.7; margin: 0;">Class</p>
                    <p style="font-size: 10px; font-weight: 500; margin: 2px 0;"><?php echo htmlspecialchars($student['class']); ?></p>
                </div>
            </div>
        </div>

        <!-- QR Code Section -->
        <?php if ($show_qr): ?>
            <div style="position: absolute; bottom: 4mm; right: 4mm;">
                <img src="<?php echo $qr_code_url; ?>" style="width: 22mm; height: 22mm;">
            </div>
        <?php endif; ?>

        <!-- Bottom Text -->
        <div style="position: absolute; bottom: 3mm; left: 4mm;">
            <p style="color: rgba(255,255,255,0.6); font-size: 6px; margin: 0;">Valid ID Card • <?php echo date('Y'); ?></p>
        </div>
    </div>
<?php
    return ob_get_clean();
}
?>