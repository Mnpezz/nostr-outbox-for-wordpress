<?php
/**
 * Template for WooCommerce My Account - Zap Rewards page
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="zap-rewards-dashboard">
    <h2>⚡ <?php _e( 'Your Bitcoin Lightning Rewards', 'nostr-outbox-wordpress' ); ?></h2>
    
    <div class="zap-address-section">
        <h3><?php _e( 'Your Lightning Receiving Address', 'nostr-outbox-wordpress' ); ?></h3>
        <div style="background: #e7f5ff; border-left: 4px solid #2196f3; padding: 12px; margin-bottom: 15px;">
            <strong>⚡ <?php _e( 'Three Ways to Receive Rewards:', 'nostr-outbox-wordpress' ); ?></strong>
            <ul style="margin: 8px 0 0 20px; font-size: 14px;">
                <li>✅ <strong><?php _e( 'Coinos username', 'nostr-outbox-wordpress' ); ?></strong> (<?php _e( 'recommended, FREE', 'nostr-outbox-wordpress' ); ?>): <code>abcdefg</code></li>
                <li>✅ <strong><?php _e( 'Lightning address', 'nostr-outbox-wordpress' ); ?></strong> (<?php _e( 'small fee', 'nostr-outbox-wordpress' ); ?>): <code>user@strike.me</code></li>
                <li>✅ <strong><?php _e( 'Lightning invoice', 'nostr-outbox-wordpress' ); ?></strong> (<?php _e( 'for one-time', 'nostr-outbox-wordpress' ); ?>): <code>lnbc...</code></li>
            </ul>
            <p style="margin: 8px 0 0 0; font-size: 13px; color: #666;">
                💡 <strong><?php _e( 'Best option:', 'nostr-outbox-wordpress' ); ?></strong> <?php _e( 'Use your Coinos username for instant, FREE transfers!', 'nostr-outbox-wordpress' ); ?>
            </p>
        </div>
        
        <?php
        $lightning_address = get_user_meta( $user_id, 'zap_address', true );
        if ( $lightning_address ) {
            // Detect what type of address it is
            $is_coinos = preg_match( '/^[a-zA-Z0-9_]+$/', $lightning_address );
            $is_ln_address = preg_match( '/^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $lightning_address );
            $is_invoice = strpos( $lightning_address, 'lnbc' ) === 0 || strpos( $lightning_address, 'lntb' ) === 0;
            
            if ( $is_coinos ) {
                echo '<div style="background: #f0fdf4; border-left: 4px solid #46b450; padding: 12px; margin-bottom: 15px;">';
                echo '<strong>✅ ' . __( 'Coinos Username:', 'nostr-outbox-wordpress' ) . '</strong> <code>' . esc_html( $lightning_address ) . '</code><br>';
                echo '<small>💰 ' . __( 'Free instant transfers!', 'nostr-outbox-wordpress' ) . '</small>';
                echo '</div>';
            } elseif ( $is_ln_address ) {
                echo '<div style="background: #e7f5ff; border-left: 4px solid #2196f3; padding: 12px; margin-bottom: 15px;">';
                echo '<strong>⚡ ' . __( 'Lightning Address:', 'nostr-outbox-wordpress' ) . '</strong> <code>' . esc_html( $lightning_address ) . '</code><br>';
                echo '<small>' . __( 'Small Lightning Network fees may apply.', 'nostr-outbox-wordpress' ) . '</small>';
                echo '</div>';
            } elseif ( $is_invoice ) {
                echo '<div style="background: #fff3cd; border-left: 4px solid #f0b849; padding: 12px; margin-bottom: 15px;">';
                echo '<strong>🔗 ' . __( 'Lightning Invoice:', 'nostr-outbox-wordpress' ) . '</strong> <code>' . esc_html( substr( $lightning_address, 0, 30 ) ) . '...</code><br>';
                echo '<small>' . __( 'One-time use only. Update with a Coinos username for recurring rewards!', 'nostr-outbox-wordpress' ) . '</small>';
                echo '</div>';
            } else {
                echo '<p>' . __( 'Current:', 'nostr-outbox-wordpress' ) . ' <code>' . esc_html( $lightning_address ) . '</code></p>';
            }
        }
        ?>
        
        <form method="post" action="">
            <input type="text" 
                   name="lightning_address" 
                   id="lightning_address" 
                   value="<?php echo esc_attr( $lightning_address ); ?>" 
                   placeholder="username, user@domain.com, or lnbc..."
                   class="regular-text"
                   style="padding: 10px; width: 100%; max-width: 500px; border: 2px solid #ddd; border-radius: 4px;"
            />
            <?php wp_nonce_field( 'update_lightning_address', 'lightning_address_nonce' ); ?>
            <button type="submit" class="button button-primary" style="margin-top: 10px;">
                <?php _e( 'Update Address', 'nostr-outbox-wordpress' ); ?>
            </button>
            
            <p class="description" style="margin-top: 10px;">
                💡 <strong><?php _e( 'How to get your username:', 'nostr-outbox-wordpress' ); ?></strong>
            </p>
            <ol style="font-size: 14px; margin-left: 20px;">
                <li><?php _e( 'Go to', 'nostr-outbox-wordpress' ); ?> <a href="https://coinos.io" target="_blank">coinos.io</a></li>
                <li><?php _e( 'Login or create account', 'nostr-outbox-wordpress' ); ?></li>
                <li><?php _e( 'Your username is shown at the top (e.g., "abcdefg")', 'nostr-outbox-wordpress' ); ?></li>
                <li><?php _e( 'Enter just the username here (no @ symbols)', 'nostr-outbox-wordpress' ); ?></li>
            </ol>
        </form>
    </div>

    <div class="rewards-history">
        <h3><?php _e( 'Rewards History', 'nostr-outbox-wordpress' ); ?></h3>
        <?php if ( $rewards ) : ?>
            <table class="rewards-table">
                <thead>
                    <tr>
                        <th><?php _e( 'Date', 'nostr-outbox-wordpress' ); ?></th>
                        <th><?php _e( 'Type', 'nostr-outbox-wordpress' ); ?></th>
                        <th><?php _e( 'Amount (sats)', 'nostr-outbox-wordpress' ); ?></th>
                        <th><?php _e( 'Status', 'nostr-outbox-wordpress' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rewards as $reward ) : ?>
                        <tr>
                            <td><?php echo esc_html( date( 'Y-m-d H:i', strtotime( $reward->created_at ) ) ); ?></td>
                            <td><?php echo esc_html( ucfirst( $reward->reward_type ) ); ?></td>
                            <td><?php echo esc_html( $reward->amount ); ?> ⚡</td>
                            <td>
                                <?php if ( $reward->status === 'completed' ) : ?>
                                    <span class="status-completed">✓ <?php _e( 'Sent', 'nostr-outbox-wordpress' ); ?></span>
                                <?php elseif ( $reward->status === 'failed' ) : ?>
                                    <span class="status-failed">✗ <?php _e( 'Failed', 'nostr-outbox-wordpress' ); ?></span>
                                <?php elseif ( $reward->status === 'awaiting_approval' ) : ?>
                                    <span class="status-pending">⏳ <?php _e( 'Awaiting Admin Approval', 'nostr-outbox-wordpress' ); ?></span>
                                <?php else : ?>
                                    <span class="status-pending">⏳ <?php _e( 'Processing', 'nostr-outbox-wordpress' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p><?php _e( 'No rewards yet. Start earning by participating!', 'nostr-outbox-wordpress' ); ?></p>
        <?php endif; ?>
    </div>

    <div class="rewards-info">
        <h3>⚡ <?php _e( 'How to Earn Bitcoin', 'nostr-outbox-wordpress' ); ?></h3>
        <ul>
            <?php if ( get_option( 'nostr_zap_rewards_enable_comments' ) ) : ?>
                <li>💬 <?php _e( 'Comment on posts:', 'nostr-outbox-wordpress' ); ?> <strong><?php echo esc_html( get_option( 'nostr_zap_rewards_comment_amount', 100 ) ); ?> sats</strong></li>
            <?php endif; ?>
            <?php if ( get_option( 'nostr_zap_rewards_enable_reviews' ) ) : ?>
                <li>⭐ <?php _e( 'Write product reviews:', 'nostr-outbox-wordpress' ); ?> <strong><?php echo esc_html( get_option( 'nostr_zap_rewards_review_amount', 500 ) ); ?> sats</strong></li>
            <?php endif; ?>
            <?php if ( get_option( 'nostr_zap_rewards_enable_purchases' ) ) : ?>
                <li>
                    🛍️ <?php _e( 'Make purchases:', 'nostr-outbox-wordpress' ); ?> <strong><?php echo esc_html( get_option( 'nostr_zap_rewards_purchase_percentage', 1 ) ); ?>% <?php _e( 'cashback', 'nostr-outbox-wordpress' ); ?></strong> <?php _e( 'in Bitcoin', 'nostr-outbox-wordpress' ); ?>
                    <br>
                    <span style="font-size: 0.9em; color: #666; margin-left: 25px;">
                        <?php 
                        $min_purchase = get_option( 'nostr_zap_rewards_min_purchase_amount', 10 );
                        $currency = get_woocommerce_currency();
                        printf( 
                            __( '• Minimum order: %s%s', 'nostr-outbox-wordpress' ), 
                            esc_html( $currency === 'USD' ? '$' : '' ),
                            esc_html( number_format( $min_purchase, 2 ) )
                        );
                        if ( $currency !== 'USD' ) {
                            echo ' ' . esc_html( $currency );
                        }
                        ?>
                        <br>
                        <span style="margin-left: 25px;">• <?php _e( 'Cash and check payments not eligible', 'nostr-outbox-wordpress' ); ?></span>
                    </span>
                </li>
            <?php endif; ?>
        </ul>
        <p class="description">💡 100 sats ≈ $0.10 USD (<?php _e( 'varies with Bitcoin price', 'nostr-outbox-wordpress' ); ?>)</p>
    </div>
</div>

