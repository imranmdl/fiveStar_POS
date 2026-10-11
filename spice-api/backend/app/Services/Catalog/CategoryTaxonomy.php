<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * What words in an item name say about where the item belongs.
 *
 * One entry per top-level category the store sells (or may start selling):
 * its display name, business type (categories.item_type), the names it may
 * already exist under in this store, the words that point to it, and its
 * sub-categories with their own words. Keywords are matched as whole words
 * or phrases against the lower-cased item name (and its singular form), in
 * English and the common Hindi names shops use ("jeera", "badam", "chappal").
 *
 * The names match the categories seeded in migrations 002 / 034, so a
 * suggestion lands on the existing category; anything not yet in the store
 * (Perfumes, Personal Care…) is offered to create.
 *
 * Kept as data, not logic, so adding a line of business is a matter of
 * adding an entry here.
 */
final class CategoryTaxonomy
{
    /**
     * Sub-categories that describe who an item is for rather than what it
     * is. Under Clothing / Footwear these are a fallback: "men's kurta" goes
     * to Ethnic Wear, "men's shirt" to Men.
     */
    public const AUDIENCE = [
        'Men' => ['men', 'mens', 'man', 'gents', 'gent', 'male', 'boys'],
        'Women' => ['women', 'womens', 'woman', 'ladies', 'lady', 'female', 'girls'],
        'Kids' => ['kids', 'kid', 'child', 'children', 'baby', 'babies', 'infant', 'toddler', 'junior', 'boy', 'girl'],
    ];

    /** @return array<int, array{name: string, item_type: string, aliases: array<int, string>, keywords: array<int, string>, subs: array<string, array<int, string>>, audience?: bool}> */
    public static function all(): array
    {
        return [
            [
                'name' => 'Spices', 'item_type' => 'grocery', 'aliases' => ['spices', 'spice', 'masala', 'masalas'],
                'keywords' => [
                    'spice', 'masala', 'chilli', 'chili', 'mirchi', 'mirch', 'turmeric', 'haldi', 'jeera', 'cumin',
                    'coriander', 'dhania', 'dhaniya', 'pepper', 'kali mirch', 'cardamom', 'elaichi', 'elachi', 'clove',
                    'cloves', 'laung', 'cinnamon', 'dalchini', 'mustard seed', 'mustard seeds', 'rai', 'fenugreek', 'methi',
                    'ajwain', 'carom', 'hing', 'asafoetida', 'star anise', 'chakri phool', 'bay leaf', 'bay leaves',
                    'tej patta', 'nutmeg', 'jaiphal', 'mace', 'javitri', 'saffron', 'kesar', 'fennel', 'saunf', 'kasuri',
                    'kalonji', 'nigella', 'amchur', 'dry mango', 'shahi jeera', 'black salt', 'garam masala', 'paprika',
                    'oregano seasoning', 'sambar', 'rasam', 'biryani masala', 'chaat',
                ],
                'subs' => [
                    'Whole Spices' => ['whole', 'sabut', 'stick', 'sticks', 'pod', 'pods', 'seeds', 'seed', 'buds', 'flower'],
                    'Ground Spices' => ['powder', 'ground', 'pisi', 'pisa', 'crushed', 'flakes'],
                    'Spice Blends' => ['masala', 'blend', 'mix', 'seasoning', 'garam masala', 'sambar', 'rasam', 'chaat', 'biryani'],
                ],
            ],
            [
                'name' => 'Dry Fruits', 'item_type' => 'grocery', 'aliases' => ['dry fruits', 'dryfruits', 'nuts', 'dry fruit'],
                'keywords' => [
                    'almond', 'almonds', 'badam', 'cashew', 'cashews', 'kaju', 'raisin', 'raisins', 'kishmish', 'munakka',
                    'date', 'dates', 'khajur', 'khajoor', 'pistachio', 'pistachios', 'pista', 'walnut', 'walnuts', 'akhrot',
                    'anjeer', 'anjir', 'fig', 'figs', 'apricot', 'apricots', 'khubani', 'dry fruit', 'dry fruits', 'makhana',
                    'fox nut', 'foxnut', 'lotus seed', 'prunes', 'cranberry', 'cranberries', 'blueberry dried', 'hazelnut',
                    'macadamia', 'brazil nut', 'pecan', 'chilgoza', 'pine nut', 'trail mix', 'mixed nuts',
                ],
                'subs' => [
                    'Almonds' => ['almond', 'almonds', 'badam'],
                    'Cashews' => ['cashew', 'cashews', 'kaju'],
                    'Raisins & Dates' => ['raisin', 'raisins', 'kishmish', 'munakka', 'date', 'dates', 'khajur', 'khajoor'],
                    'Pistachios' => ['pistachio', 'pistachios', 'pista'],
                    'Walnuts' => ['walnut', 'walnuts', 'akhrot'],
                    'Figs & Apricots' => ['fig', 'figs', 'anjeer', 'anjir', 'apricot', 'apricots', 'khubani'],
                    'Makhana' => ['makhana', 'fox nut', 'foxnut', 'lotus seed'],
                ],
            ],
            [
                'name' => 'Seeds', 'item_type' => 'grocery', 'aliases' => ['seeds', 'seed'],
                'keywords' => [
                    'chia', 'flax', 'flaxseed', 'flaxseeds', 'alsi', 'pumpkin seed', 'pumpkin seeds', 'sunflower seed',
                    'sunflower seeds', 'watermelon seed', 'watermelon seeds', 'melon seed', 'magaz', 'sabja', 'basil seed',
                    'basil seeds', 'sesame', 'til', 'quinoa', 'hemp seed', 'seed mix',
                ],
                'subs' => [
                    'Pumpkin Seeds' => ['pumpkin'],
                    'Chia & Flax' => ['chia', 'flax', 'flaxseed', 'flaxseeds', 'alsi'],
                    'Sunflower & Melon Seeds' => ['sunflower', 'watermelon', 'melon', 'magaz'],
                    'Sesame Seeds' => ['sesame', 'til'],
                    'Basil Seeds' => ['sabja', 'basil'],
                ],
            ],
            [
                'name' => 'Herbs', 'item_type' => 'grocery', 'aliases' => ['herbs', 'herb', 'herbal'],
                'keywords' => [
                    'herb', 'herbs', 'herbal', 'tulsi', 'ashwagandha', 'moringa', 'neem', 'brahmi', 'giloy', 'shatavari',
                    'triphala', 'mulethi', 'licorice', 'stevia', 'rosemary', 'thyme', 'oregano', 'mint leaves', 'pudina',
                    'curry leaves', 'kadi patta', 'hibiscus', 'chamomile', 'green tea', 'herbal tea', 'safed musli',
                ],
                'subs' => [],
            ],
            [
                'name' => 'Grocery & Staples', 'item_type' => 'grocery', 'aliases' => ['grocery & staples', 'grocery', 'staples', 'groceries'],
                'keywords' => [
                    'rice', 'basmati', 'sona masoori', 'poha', 'oats', 'millet', 'ragi', 'jowar', 'bajra', 'wheat', 'dalia',
                    'atta', 'flour', 'maida', 'besan', 'sooji', 'suji', 'rava', 'semolina', 'dal', 'daal', 'toor', 'tur',
                    'moong', 'chana', 'urad', 'masoor', 'rajma', 'lobia', 'pulses', 'kabuli', 'sugar', 'salt', 'jaggery',
                    'gud', 'gur', 'mishri', 'biscuit', 'biscuits', 'cookies', 'namkeen', 'snack', 'snacks', 'chips',
                    'bhujia', 'mixture', 'rusk', 'noodles', 'vermicelli', 'sevai', 'papad', 'pickle', 'achar', 'honey',
                    'tea', 'chai', 'coffee', 'sauce', 'ketchup', 'jam',
                ],
                'subs' => [
                    'Rice & Grains' => ['rice', 'basmati', 'sona masoori', 'poha', 'oats', 'millet', 'ragi', 'jowar', 'bajra', 'wheat', 'dalia', 'quinoa'],
                    'Pulses & Dals' => ['dal', 'daal', 'toor', 'tur', 'moong', 'chana', 'urad', 'masoor', 'rajma', 'lobia', 'pulses', 'kabuli'],
                    'Flours & Atta' => ['atta', 'flour', 'maida', 'besan', 'sooji', 'suji', 'rava', 'semolina'],
                    'Sugar, Salt & Jaggery' => ['sugar', 'salt', 'jaggery', 'gud', 'gur', 'mishri'],
                    'Snacks & Biscuits' => ['biscuit', 'biscuits', 'cookies', 'namkeen', 'snack', 'snacks', 'chips', 'bhujia', 'mixture', 'rusk'],
                    'Tea & Coffee' => ['tea', 'chai', 'coffee'],
                    'Pickles, Sauces & Spreads' => ['pickle', 'achar', 'sauce', 'ketchup', 'jam', 'honey'],
                ],
            ],
            [
                'name' => 'Oils', 'item_type' => 'oils', 'aliases' => ['oils', 'oil', 'oils & ghee', 'edible oils'],
                'keywords' => [
                    'oil', 'oils', 'ghee', 'butter', 'vanaspati', 'dalda', 'refined oil', 'sunflower oil', 'groundnut oil',
                    'mustard oil', 'coconut oil', 'sesame oil', 'gingelly', 'olive oil', 'rice bran', 'soybean oil', 'palm oil',
                    'castor oil', 'almond oil', 'hair oil', 'essential oil',
                ],
                'subs' => [
                    'Cooking Oils' => ['refined', 'coconut oil', 'sesame oil', 'gingelly', 'sunflower oil', 'groundnut oil', 'peanut oil', 'mustard oil', 'soybean', 'rice bran', 'palm', 'olive', 'cooking', 'kachi ghani'],
                    'Cold-pressed Oils' => ['cold pressed', 'cold press', 'wood pressed', 'chekku', 'ghani', 'kolhu', 'virgin'],
                    'Ghee & Butter' => ['ghee', 'butter', 'vanaspati', 'dalda'],
                    'Hair Oils' => ['hair oil', 'hair', 'amla', 'bhringraj', 'onion oil', 'scalp', 'castor', 'almond oil', 'badam oil'],
                    'Essential Oils' => ['essential', 'lavender', 'tea tree', 'eucalyptus', 'peppermint oil', 'aroma'],
                ],
            ],
            [
                'name' => 'Clothing', 'item_type' => 'clothing', 'aliases' => ['clothing', 'clothes', 'apparel', 'garments', 'fashion'],
                'audience' => true,
                'keywords' => [
                    'shirt', 'shirts', 't shirt', 'tshirt', 'tee', 'tees', 'polo', 'kurta', 'kurti', 'saree', 'sari',
                    'salwar', 'lehenga', 'churidar', 'sherwani', 'dhoti', 'lungi', 'dupatta', 'jeans', 'trouser', 'trousers',
                    'pant', 'pants', 'chinos', 'shorts', 'bermuda', 'top', 'tops', 'dress', 'frock', 'gown', 'skirt',
                    'jacket', 'blazer', 'coat', 'sweater', 'sweatshirt', 'hoodie', 'cardigan', 'track pant', 'tracksuit',
                    'jogger', 'joggers', 'legging', 'leggings', 'nightwear', 'nighty', 'night suit', 'pyjama', 'pajama',
                    'vest', 'brief', 'briefs', 'boxer', 'boxers', 'trunk', 'bra', 'panty', 'panties', 'innerwear',
                    'lingerie', 'camisole', 'romper', 'onesie', 'school uniform', 'uniform', 'cotton', 'denim',
                ],
                'subs' => [
                    'Innerwear' => ['vest', 'brief', 'briefs', 'boxer', 'boxers', 'trunk', 'bra', 'panty', 'panties', 'innerwear', 'lingerie', 'camisole', 'slip'],
                    'Ethnic Wear' => ['kurta', 'kurti', 'saree', 'sari', 'salwar', 'lehenga', 'churidar', 'sherwani', 'dhoti', 'dupatta', 'ethnic', 'anarkali', 'nehru'],
                ],
            ],
            [
                'name' => 'Footwear', 'item_type' => 'footwear', 'aliases' => ['footwear', 'shoes', 'footwears'],
                'audience' => true,
                'keywords' => [
                    'shoe', 'shoes', 'sandal', 'sandals', 'slipper', 'slippers', 'chappal', 'chappals', 'sneaker',
                    'sneakers', 'boot', 'boots', 'flip flop', 'flip flops', 'floater', 'floaters', 'loafer', 'loafers',
                    'heel', 'heels', 'wedges', 'crocs', 'clogs', 'mojari', 'jutti', 'juttis', 'kolhapuri', 'footwear',
                    'ballerina', 'bellies', 'school shoe',
                ],
                'subs' => [
                    'Sports Shoes' => ['sports', 'sport', 'running', 'training', 'gym', 'walking', 'jogging', 'sneaker', 'sneakers', 'studs'],
                    'Sandals & Slippers' => ['sandal', 'sandals', 'slipper', 'slippers', 'chappal', 'chappals', 'flip flop', 'flip flops', 'floater', 'floaters', 'crocs', 'clogs'],
                ],
            ],
            [
                'name' => 'Perfumes', 'item_type' => 'perfumes', 'aliases' => ['perfumes', 'perfume', 'fragrances', 'fragrance', 'attar'],
                'keywords' => [
                    'perfume', 'perfumes', 'attar', 'attars', 'ittar', 'itr', 'itra', 'oud', 'deo', 'deodorant', 'deodorants',
                    'body spray', 'fragrance', 'cologne', 'edp', 'edt', 'eau de parfum', 'eau de toilette', 'body mist',
                    'roll on', 'musk', 'parfum',
                ],
                'subs' => [
                    'Attar' => ['attar', 'attars', 'ittar', 'itr', 'itra', 'oud', 'musk'],
                    'Perfumes' => ['perfume', 'perfumes', 'edp', 'edt', 'eau de parfum', 'eau de toilette', 'cologne', 'parfum', 'fragrance'],
                    'Deodorants & Body Sprays' => ['deo', 'deodorant', 'deodorants', 'body spray', 'body mist', 'roll on'],
                ],
            ],
            [
                'name' => 'Personal Care', 'item_type' => 'personal_care', 'aliases' => ['personal care', 'beauty', 'beauty & personal care', 'cosmetics', 'toiletries'],
                'keywords' => [
                    'soap', 'shampoo', 'conditioner', 'face wash', 'facewash', 'body wash', 'hand wash', 'handwash',
                    'sanitizer', 'sanitiser', 'cream', 'lotion', 'moisturizer', 'moisturiser', 'sunscreen', 'face pack',
                    'serum', 'lip balm', 'lipstick', 'kajal', 'talc', 'talcum', 'toothpaste', 'tooth paste', 'toothbrush',
                    'mouthwash', 'hair colour', 'hair color', 'hair dye', 'henna', 'mehndi', 'hair gel', 'shaving', 'razor',
                    'aloe vera gel', 'ubtan', 'multani mitti', 'face powder', 'nail polish', 'sanitary', 'pads', 'diaper',
                    'diapers', 'wipes',
                ],
                'subs' => [
                    'Bath & Body' => ['soap', 'body wash', 'hand wash', 'handwash', 'sanitizer', 'sanitiser', 'lotion', 'talc', 'talcum', 'ubtan'],
                    'Hair Care' => ['shampoo', 'conditioner', 'hair colour', 'hair color', 'hair dye', 'henna', 'mehndi', 'hair gel'],
                    'Skin Care' => ['cream', 'face wash', 'facewash', 'moisturizer', 'moisturiser', 'sunscreen', 'face pack', 'serum', 'lip balm', 'aloe vera gel', 'multani mitti'],
                    'Oral Care' => ['toothpaste', 'tooth paste', 'toothbrush', 'mouthwash'],
                    'Shaving & Grooming' => ['shaving', 'razor', 'trimmer', 'beard'],
                    'Hygiene' => ['sanitary', 'pads', 'diaper', 'diapers', 'wipes'],
                    'Makeup' => ['lipstick', 'kajal', 'nail polish', 'face powder', 'eyeliner', 'foundation'],
                ],
            ],
            [
                'name' => 'Pooja Items', 'item_type' => 'pooja', 'aliases' => ['pooja items', 'pooja', 'puja', 'puja items', 'pooja essentials'],
                'keywords' => [
                    'agarbatti', 'agarbathi', 'incense', 'dhoop', 'dhup', 'camphor', 'kapur', 'karpur', 'diya', 'diyas',
                    'wick', 'wicks', 'batti', 'kumkum', 'roli', 'sindoor', 'chandan', 'sandalwood', 'pooja', 'puja',
                    'kalash', 'havan', 'samagri', 'gangajal', 'mauli', 'kalava', 'janeu', 'rudraksha', 'loban', 'guggal',
                ],
                'subs' => [
                    'Agarbatti & Dhoop' => ['agarbatti', 'agarbathi', 'incense', 'dhoop', 'dhup', 'loban', 'guggal', 'sambrani'],
                    'Pooja Essentials' => ['camphor', 'kapur', 'karpur', 'diya', 'diyas', 'wick', 'wicks', 'batti', 'kumkum', 'roli', 'sindoor', 'chandan', 'kalash', 'havan', 'samagri', 'gangajal', 'mauli', 'kalava', 'janeu', 'rudraksha'],
                ],
            ],
            [
                'name' => 'Home & Kitchen', 'item_type' => 'home_kitchen', 'aliases' => ['home & kitchen', 'home and kitchen', 'kitchen', 'household', 'home'],
                'keywords' => [
                    'tiffin', 'lunch box', 'plate', 'plates', 'bowl', 'bowls', 'glass', 'tumbler', 'spoon', 'spoons',
                    'ladle', 'kadai', 'kadhai', 'tawa', 'pressure cooker', 'cooker', 'pan', 'frying pan', 'knife', 'peeler',
                    'grater', 'container', 'containers', 'jar', 'jars', 'water bottle', 'flask', 'casserole', 'broom',
                    'mop', 'bucket', 'dustbin', 'detergent', 'washing powder', 'dishwash', 'dish wash', 'cleaner',
                    'phenyl', 'scrubber', 'toilet', 'floor cleaner', 'napkin', 'napkins', 'tissue', 'tissues', 'foil',
                    'cling wrap', 'garbage bag', 'paper plate', 'paper cup', 'matchbox', 'candle', 'mosquito', 'repellent',
                ],
                'subs' => [
                    'Kitchenware' => ['tiffin', 'lunch box', 'plate', 'plates', 'bowl', 'bowls', 'glass', 'tumbler', 'spoon', 'spoons', 'ladle', 'kadai', 'kadhai', 'tawa', 'cooker', 'pan', 'knife', 'peeler', 'grater', 'container', 'containers', 'jar', 'jars', 'water bottle', 'flask', 'casserole'],
                    'Cleaning Supplies' => ['broom', 'mop', 'bucket', 'dustbin', 'detergent', 'washing powder', 'dishwash', 'dish wash', 'cleaner', 'phenyl', 'scrubber', 'toilet', 'floor cleaner'],
                    'Disposables' => ['napkin', 'napkins', 'tissue', 'tissues', 'foil', 'cling wrap', 'garbage bag', 'paper plate', 'paper cup'],
                    'Household Essentials' => ['matchbox', 'candle', 'mosquito', 'repellent', 'battery', 'bulb'],
                ],
            ],
            [
                'name' => 'Toys', 'item_type' => 'toys', 'aliases' => ['toys', 'toy', 'toys & games'],
                'keywords' => [
                    'toy', 'toys', 'doll', 'dolls', 'teddy', 'plush', 'soft toy', 'puzzle', 'puzzles', 'lego', 'blocks',
                    'building blocks', 'remote control', 'rc car', 'toy car', 'action figure', 'robot', 'board game',
                    'ludo', 'carrom', 'chess', 'snakes and ladders', 'uno', 'playing cards', 'kite', 'spinning top',
                    'rattle', 'play dough', 'clay', 'skipping rope', 'frisbee', 'yo yo', 'water gun',
                ],
                'subs' => [
                    'Soft Toys' => ['teddy', 'plush', 'soft toy', 'soft'],
                    'Educational Toys' => ['educational', 'learning', 'abacus', 'blocks', 'building blocks', 'lego', 'alphabet', 'puzzle', 'play dough', 'clay', 'rattle'],
                    'Action Figures & Cars' => ['action figure', 'superhero', 'car', 'truck', 'robot', 'remote control', 'rc car', 'toy car', 'water gun'],
                    'Games & Puzzles' => ['board game', 'ludo', 'carrom', 'chess', 'snakes and ladders', 'uno', 'playing cards', 'puzzle', 'puzzles'],
                    'Outdoor Toys' => ['ball', 'bat', 'kite', 'frisbee', 'skipping rope', 'cycle', 'yo yo', 'spinning top'],
                ],
            ],
            [
                'name' => 'Stationery', 'item_type' => 'stationery', 'aliases' => ['stationery', 'stationary', 'office & school'],
                'keywords' => [
                    'pen', 'pens', 'pencil', 'pencils', 'notebook', 'note book', 'notebooks', 'eraser', 'rubber',
                    'sharpener', 'scale', 'ruler', 'crayon', 'crayons', 'marker', 'markers', 'highlighter', 'glue', 'fevicol',
                    'stapler', 'stapler pins', 'file', 'folder', 'register', 'diary', 'a4', 'paper', 'chart paper',
                    'sketch', 'sketch pens', 'colour pencils', 'color pencils', 'geometry box', 'compass', 'refill',
                    'sticky notes', 'calculator', 'tape', 'cello tape', 'whitener', 'drawing book', 'watercolour', 'paint brush',
                ],
                'subs' => [
                    'Pens & Pencils' => ['pen', 'pens', 'pencil', 'pencils', 'marker', 'markers', 'highlighter', 'refill', 'ball pen', 'gel pen'],
                    'Notebooks & Paper' => ['notebook', 'note book', 'notebooks', 'register', 'diary', 'a4', 'paper', 'chart paper', 'sticky notes'],
                    'Art & Craft' => ['crayon', 'crayons', 'sketch', 'sketch pens', 'colour pencils', 'color pencils', 'watercolour', 'paint', 'paint brush', 'drawing book', 'glitter'],
                    'Office Supplies' => ['stapler', 'stapler pins', 'file', 'folder', 'calculator', 'tape', 'cello tape', 'clip', 'clips', 'whitener', 'glue', 'fevicol'],
                    'School Supplies' => ['geometry box', 'compass', 'eraser', 'rubber', 'sharpener', 'scale', 'ruler', 'school bag', 'pencil box'],
                ],
            ],
        ];
    }
}
