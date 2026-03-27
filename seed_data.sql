-- Too Many Coins - Seed Data

-- Cosmetic catalog items (using canonical price tiers: 10, 25, 60, 150, 400)
INSERT INTO cosmetic_catalog (name, description, category, price_global_stars, css_class) VALUES
-- Avatar Frames (10, 25, 60, 150, 400)
('Bronze Ring', 'A simple bronze frame around your avatar.', 'avatar_frame', 10, 'frame-bronze'),
('Silver Ring', 'A polished silver frame.', 'avatar_frame', 25, 'frame-silver'),
('Gold Ring', 'A gleaming gold frame.', 'avatar_frame', 60, 'frame-gold'),
('Diamond Ring', 'A sparkling diamond-encrusted frame.', 'avatar_frame', 150, 'frame-diamond'),
('Celestial Ring', 'An ethereal cosmic frame with animated stars.', 'avatar_frame', 400, 'frame-celestial'),

-- Name Colors (10, 25, 60, 150, 400)
('Ember', 'A warm orange-red name color.', 'name_color', 10, 'name-ember'),
('Ocean', 'A deep blue name color.', 'name_color', 25, 'name-ocean'),
('Verdant', 'A rich green name color.', 'name_color', 60, 'name-verdant'),
('Royal Purple', 'A majestic purple name color.', 'name_color', 150, 'name-royal'),
('Prismatic', 'A shifting rainbow name color.', 'name_color', 400, 'name-prismatic'),

-- Profile Backgrounds (10, 25, 60, 150, 400)
('Parchment', 'A subtle aged paper texture.', 'profile_bg', 10, 'bg-parchment'),
('Midnight', 'A dark starry background.', 'profile_bg', 25, 'bg-midnight'),
('Aurora', 'Northern lights shimmer behind your profile.', 'profile_bg', 60, 'bg-aurora'),
('Volcanic', 'Molten lava flows in the background.', 'profile_bg', 150, 'bg-volcanic'),
('Void', 'An otherworldly cosmic void.', 'profile_bg', 400, 'bg-void'),

-- Titles (10, 25, 60, 150, 400)
('Newcomer', 'A humble beginning.', 'title', 10, 'title-newcomer'),
('Trader', 'Known for making deals.', 'title', 25, 'title-trader'),
('Strategist', 'A calculated mind.', 'title', 60, 'title-strategist'),
('Magnate', 'A titan of the economy.', 'title', 150, 'title-magnate'),
('Legend', 'A name spoken in whispers.', 'title', 400, 'title-legend'),

-- Effects (25, 60, 150, 400)
('Sparkle', 'Subtle sparkle particles on your profile.', 'effect', 25, 'effect-sparkle'),
('Flame', 'Gentle flame wisps around your name.', 'effect', 60, 'effect-flame'),
('Lightning', 'Crackling energy surrounds your profile.', 'effect', 150, 'effect-lightning'),
('Supernova', 'An explosive cosmic effect.', 'effect', 400, 'effect-supernova');
