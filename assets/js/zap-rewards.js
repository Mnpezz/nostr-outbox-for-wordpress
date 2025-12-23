/**
 * WP Zap Rewards - Frontend JavaScript
 * Shows notifications when rewards are triggered
 */

(function($) {
    'use strict';

    // Check if reward data is available
    if (typeof zapRewardsData === 'undefined') {
        return;
    }

    // Create notification element
    function createNotification() {
        const notification = $('<div>', {
            class: 'zap-reward-notification',
            html: `
                <div class="zap-notification-content">
                    <span class="zap-icon">⚡</span>
                    <span class="zap-message"></span>
                </div>
            `
        });
        
        $('body').append(notification);
        return notification;
    }

    // Show notification
    function showNotification(message, duration = 5000, className = '') {
        let notification = $('.zap-reward-notification');
        
        if (notification.length === 0) {
            notification = createNotification();
        }
        
        // Reset classes
        notification.removeClass('zap-success zap-warning zap-error');
        if (className) {
            notification.addClass(className);
        }
        
        notification.find('.zap-message').text(message);
        notification.addClass('show');
        
        // Auto-hide after duration
        setTimeout(() => {
            notification.removeClass('show');
        }, duration);
    }

    // Monitor comment form submission
    function setupCommentFormMonitor() {
        const commentForms = $('form#commentform, form.comment-form');
        
        if (commentForms.length === 0) {
            return;
        }

        commentForms.on('submit', function(e) {
            // Check if user has address and rewards are enabled
            if (zapRewardsData.hasAddress && zapRewardsData.commentsEnabled && zapRewardsData.commentAmount > 0) {
                // Check if this is a review form
                const isReview = $(this).find('.stars, [name="rating"]').length > 0;
                
                // Show appropriate message based on approval settings
                let message;
                if (zapRewardsData.needsApproval) {
                    const amount = isReview ? zapRewardsData.reviewAmount : zapRewardsData.commentAmount;
                    message = `${amount} sats awaiting admin approval ⏳`;
                } else {
                    const amount = isReview ? zapRewardsData.reviewAmount : zapRewardsData.commentAmount;
                    message = `${amount} sats on the way! ⚡`;
                }
                
                showNotification(message);
            }
        });
    }

    // Monitor WooCommerce review form
    function setupReviewFormMonitor() {
        const reviewForms = $('form#commentform.comment-form, form.review-form');
        
        reviewForms.on('submit', function(e) {
            // Check if this is a review (has rating)
            const hasRating = $(this).find('.stars, [name="rating"]').length > 0;
            
            if (hasRating && zapRewardsData.hasAddress && zapRewardsData.reviewsEnabled && zapRewardsData.reviewAmount > 0) {
                const amount = zapRewardsData.reviewAmount;
                
                // Show appropriate message
                const message = zapRewardsData.needsApproval 
                    ? `${amount} sats awaiting admin approval ⏳`
                    : `${amount} sats on the way! ⚡`;
                    
                showNotification(message);
            }
        });
    }

    // Add reward badge to comment/review buttons
    function addRewardBadges() {
        if (!zapRewardsData.hasAddress) {
            return;
        }

        // Add badge to comment submit button
        if (zapRewardsData.commentsEnabled && zapRewardsData.commentAmount > 0) {
            const commentSubmit = $('form#commentform [type="submit"], form.comment-form [type="submit"]');
            commentSubmit.each(function() {
                if (!$(this).find('.zap-badge').length) {
                    $(this).append(`<span class="zap-badge">+${zapRewardsData.commentAmount}⚡</span>`);
                }
            });
        }

        // Add badge to review submit button (if different from comment button)
        if (zapRewardsData.reviewsEnabled && zapRewardsData.reviewAmount > 0) {
            const reviewSubmit = $('form.review-form [type="submit"]');
            reviewSubmit.each(function() {
                if (!$(this).find('.zap-badge').length && $(this).closest('form').find('.stars').length) {
                    $(this).append(`<span class="zap-badge">+${zapRewardsData.reviewAmount}⚡</span>`);
                }
            });
        }
    }

    // Show server-side message if available
    function showServerMessage() {
        if (zapRewardsData.rewardMessage) {
            const msg = zapRewardsData.rewardMessage;
            
            // Different duration and styling based on type
            let duration = 5000;
            let className = '';
            
            if (msg.type === 'limit') {
                duration = 8000; // Show limit message longer
                className = 'zap-warning';
            } else if (msg.type === 'error') {
                duration = 8000;
                className = 'zap-error';
            } else if (msg.type === 'success') {
                className = 'zap-success';
            }
            
            showNotification(msg.message, duration, className);
        }
    }

    // Initialize when DOM is ready
    $(document).ready(function() {
        setupCommentFormMonitor();
        setupReviewFormMonitor();
        addRewardBadges();
        showServerMessage(); // Show any server messages
    });

})(jQuery);

