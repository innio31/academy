<?php
// /central_bank/id_cards/templates/card_back.php - Back side of ID card

function renderCardBack($student, $school, $settings)
{
    $primary = $settings['primary_color'];
    $secondary = $settings['secondary_color'];
    $back_text = $settings['card_back_text'];

    ob_start();
?>
    <div class="id-card-back" style="
        width: 85.6mm;
        height: 54mm;
        background: white;
        border-radius: 12px;
        padding: 4mm;
        position: relative;
        font-family: 'Segoe UI', Arial, sans-serif;
        border: 1px solid #ddd;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    ">
        <!-- Header Stripe -->
        <div style="
            background: linear-gradient(90deg, <?php echo $primary; ?> 0%, <?php echo $secondary; ?> 100%);
            height: 8mm;
            margin: -4mm -4mm 0 -4mm;
            border-radius: 12px 12px 0 0;
        "></div>

        <!-- Important Information -->
        <div style="margin: 12px 0 8px; text-align: center;">
            <h4 style="color: <?php echo $primary; ?>; font-size: 10px; margin: 0;">IMPORTANT INFORMATION</h4>
            <div style="width: 30px; height: 2px; background: <?php echo $secondary; ?>; margin: 4px auto;"></div>
        </div>

        <!-- Terms & Conditions -->
        <div style="background: #f8f9fa; border-radius: 8px; padding: 8px; margin-bottom: 8px;">
            <p style="font-size: 6px; color: #555; line-height: 1.4; margin: 0;">
                <?php echo nl2br(htmlspecialchars($back_text)); ?>
            </p>
        </div>

        <!-- Contact Information -->
        <div style="margin-bottom: 8px;">
            <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                <span style="font-size: 8px;">📞</span>
                <span style="font-size: 7px; color: #666;">Emergency: <?php echo htmlspecialchars($student['parent_phone'] ?? 'N/A'); ?></span>
            </div>
            <div style="display: flex; align-items: center; gap: 6px;">
                <span style="font-size: 8px;">👨‍👩‍👧</span>
                <span style="font-size: 7px; color: #666;">Guardian: <?php echo htmlspecialchars($student['guardian_name'] ?? $student['parent_name'] ?? 'N/A'); ?></span>
            </div>
        </div>

        <!-- Footer -->
        <div style="
            position: absolute;
            bottom: 4mm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 5px;
            color: #aaa;
        ">
            <p style="margin: 0;">This ID card is the property of <?php echo htmlspecialchars($school['school_name']); ?></p>
            <p style="margin: 2px 0 0;">Report lost cards immediately to the school administration</p>
        </div>

        <!-- Signature Line -->
        <div style="position: absolute; bottom: 4mm; right: 4mm; text-align: center;">
            <div style="width: 25mm; height: 0.5px; background: #ccc; margin-bottom: 2px;"></div>
            <p style="font-size: 5px; color: #999; margin: 0;">Authorized Signature</p>
        </div>
    </div>
<?php
    return ob_get_clean();
}
?>