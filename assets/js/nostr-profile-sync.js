/**
 * Nostr Profile Sync
 * 
 * Fetches user profile metadata (kind 0) from Nostr relays and updates WordPress profile
 */

(function() {
    'use strict';

    // Check if we have NostrTools available
    if (typeof window.NostrTools === 'undefined') {
        console.warn('NostrTools not available for profile sync');
        return;
    }

    /**
     * Fetch profile metadata from Nostr relays
     */
    async function fetchNostrProfile(pubkey, relays) {
        try {
            console.log('Fetching Nostr profile for', pubkey.substring(0, 16) + '...');
            
            const pool = new window.NostrTools.SimplePool();
            
            // Query for kind 0 metadata events
            const events = await pool.list(relays, [{
                kinds: [0],
                authors: [pubkey],
                limit: 1
            }]);
            
            pool.close(relays);
            
            if (!events || events.length === 0) {
                throw new Error('No profile found on relays');
            }
            
            // Parse metadata from the most recent event
            const metadata = JSON.parse(events[0].content);
            
            console.log('Found Nostr profile:', metadata);
            
            return {
                name: metadata.name || metadata.display_name || '',
                about: metadata.about || '',
                picture: metadata.picture || metadata.image || '',
                nip05: metadata.nip05 || ''
            };
        } catch (error) {
            console.error('Error fetching Nostr profile:', error);
            throw error;
        }
    }

    /**
     * Update WordPress profile via AJAX
     */
    async function updateWordPressProfile(userId, profileData) {
        try {
            const response = await fetch(nostrProfileSyncData.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'update_profile_from_nostr',
                    nonce: nostrProfileSyncData.nonce,
                    user_id: userId,
                    profile_data: JSON.stringify(profileData)
                })
            });
            
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.data?.message || 'Failed to update profile');
            }
            
            return data;
        } catch (error) {
            console.error('Error updating WordPress profile:', error);
            throw error;
        }
    }

    /**
     * Main sync function
     */
    window.syncNostrProfile = async function(pubkey, userId, relays) {
        try {
            // Fetch from Nostr
            const profileData = await fetchNostrProfile(pubkey, relays);
            
            // Update WordPress
            await updateWordPressProfile(userId, profileData);
            
            return true;
        } catch (error) {
            throw error;
        }
    };

    console.log('✅ Nostr profile sync ready');
})();

