/**
 * NB Chain Link - Widget JS
 * Version 1.1.0 - December 22, 2025
 *
 * Handles carousel navigation, live links, random, and rating
 */

(function($) {
    'use strict';

    // Initialize all widgets on page
    $(document).ready(function() {
        $('.nb-chain-link-widget').each(function() {
            initWidget($(this));
        });
    });

    function initWidget($widget) {
        var mode = $widget.data('mode');
        var ringId = $widget.data('ring');
        var members = $widget.data('members');
        var currentIndex = parseInt($widget.data('index')) || 0;

        if (!members || members.length === 0) return;

        // Navigation buttons
        $widget.find('.nb-prev').on('click', function() {
            currentIndex = (currentIndex - 1 + members.length) % members.length;

            if (mode === 'live') {
                // Live mode - navigate immediately
                navigateToMember(members[currentIndex]);
            } else {
                // Carousel mode - update display
                updateDisplay($widget, members[currentIndex]);
                $widget.data('index', currentIndex);
            }
        });

        $widget.find('.nb-next').on('click', function() {
            currentIndex = (currentIndex + 1) % members.length;

            if (mode === 'live') {
                navigateToMember(members[currentIndex]);
            } else {
                updateDisplay($widget, members[currentIndex]);
                $widget.data('index', currentIndex);
            }
        });

        // Random button
        $widget.find('.nb-random').on('click', function() {
            var randomIndex = Math.floor(Math.random() * members.length);

            // Avoid landing on current site if possible
            if (members.length > 1) {
                var siteUrl = nbChainLink.site_url;
                var attempts = 0;
                while (members[randomIndex].url === siteUrl && attempts < 10) {
                    randomIndex = Math.floor(Math.random() * members.length);
                    attempts++;
                }
            }

            navigateToMember(members[randomIndex]);
        });

        // Visit button (update href when member changes)
        $widget.find('.nb-visit').on('click', function(e) {
            // Let the link work naturally - href is updated in updateDisplay
        });

        // Rate buttons (directory mode)
        $widget.find('.nb-rate-btn').on('click', function() {
            var targetUrl = $(this).data('url');
            showRatingPopup(ringId, targetUrl);
        });
    }

    function updateDisplay($widget, member) {
        // Update image
        var $image = $widget.find('.nb-member-image');
        if (member.image) {
            if ($image.length) {
                $image.find('img').attr('src', member.image);
            } else {
                $widget.find('.nb-member-display').prepend(
                    '<div class="nb-member-image"><img src="' + member.image + '" alt=""></div>'
                );
            }
        } else {
            $image.remove();
        }

        // Update text
        $widget.find('.nb-member-name').text(member.name);

        var $excerpt = $widget.find('.nb-member-excerpt');
        if (member.excerpt) {
            if ($excerpt.length) {
                $excerpt.text(member.excerpt);
            } else {
                $widget.find('.nb-member-name').after(
                    '<div class="nb-member-excerpt">' + escapeHtml(member.excerpt) + '</div>'
                );
            }
        } else {
            $excerpt.remove();
        }

        // Update rating
        var avgRating = 0;
        if (member.ratings && Object.keys(member.ratings).length > 0) {
            var total = 0;
            for (var key in member.ratings) {
                total += member.ratings[key];
            }
            avgRating = total / Object.keys(member.ratings).length;
        }

        var $rating = $widget.find('.nb-member-rating');
        if (avgRating > 0) {
            var stars = renderStars(avgRating);
            if ($rating.length) {
                $rating.html(stars + ' (' + avgRating.toFixed(1) + ')');
            } else {
                $widget.find('.nb-member-info').append(
                    '<div class="nb-member-rating">' + stars + ' (' + avgRating.toFixed(1) + ')</div>'
                );
            }
        } else {
            $rating.remove();
        }

        // Update visit link
        var visitUrl = member.page_url || member.url;
        $widget.find('.nb-visit').attr('href', visitUrl);
    }

    function navigateToMember(member) {
        var url = member.page_url || member.url;
        window.open(url, '_blank');
    }

    function renderStars(rating) {
        var full = Math.floor(rating);
        var half = (rating - full) >= 0.5 ? 1 : 0;
        var empty = 5 - full - half;

        var stars = '';
        for (var i = 0; i < full; i++) stars += '⭐';
        if (half) stars += '⭐';
        for (var i = 0; i < empty; i++) stars += '☆';

        return stars;
    }

    function showRatingPopup(ringId, targetUrl) {
        // Remove existing popup
        $('.nb-rating-overlay, .nb-rating-popup').remove();

        // Create overlay
        var $overlay = $('<div class="nb-rating-overlay"></div>');

        // Create popup
        var $popup = $('<div class="nb-rating-popup">' +
            '<h4>Rate this site</h4>' +
            '<div class="nb-rating-stars">' +
                '<span data-rating="1">⭐</span>' +
                '<span data-rating="2">⭐</span>' +
                '<span data-rating="3">⭐</span>' +
                '<span data-rating="4">⭐</span>' +
                '<span data-rating="5">⭐</span>' +
            '</div>' +
            '<p style="margin-top:15px;font-size:12px;color:#666;">Click a star to rate</p>' +
        '</div>');

        // Hover effect
        $popup.find('.nb-rating-stars span').on('mouseenter', function() {
            var rating = $(this).data('rating');
            $(this).prevAll().addBack().addClass('active');
            $(this).nextAll().removeClass('active');
        }).on('mouseleave', function() {
            $popup.find('.nb-rating-stars span').removeClass('active');
        });

        // Click to rate
        $popup.find('.nb-rating-stars span').on('click', function() {
            var rating = $(this).data('rating');
            submitRating(ringId, targetUrl, rating);
            closePopup();
        });

        // Close on overlay click
        $overlay.on('click', closePopup);

        function closePopup() {
            $overlay.remove();
            $popup.remove();
        }

        $('body').append($overlay).append($popup);
    }

    function submitRating(ringId, targetUrl, rating) {
        $.ajax({
            url: nbChainLink.ajaxurl,
            method: 'POST',
            data: {
                action: 'nb_chain_link_rate',
                nonce: nbChainLink.nonce,
                ring_id: ringId,
                target_url: targetUrl,
                rating: rating
            },
            success: function(response) {
                if (response.success) {
                    alert('Thanks for rating!');
                } else {
                    alert('Rating failed: ' + (response.data || 'Unknown error'));
                }
            },
            error: function() {
                alert('Could not submit rating. Please try again.');
            }
        });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

})(jQuery);
