<?php
// elasticsearch_ai_mock.php
// Mock Elasticsearch AI service for intelligent book searching
// This provides AI-powered search without users knowing it's AI

class ElasticsearchAIMock {
    private static $instance = null;
    private $booksData = [];
    private $aiModel = 'bert-like-embedding';
    private $searchHistory = [];
    private $semanticPatterns = [];
    private $misspellingCorrections = [];
    
    private function __construct() {
        $this->initializeAIModel();
        $this->initializeMisspellingCorrections();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function initializeAIModel() {
        // Simulate AI model loading
        $this->aiModel = 'hybrid-search-v1';
        $this->searchHistory = [];
        
        // Load comprehensive semantic understanding patterns (200+ patterns)
        $this->semanticPatterns = [
            'programming' => ['code', 'software', 'developer', 'algorithm', 'database', 'web', 'mobile', 'app', 
                            'program', 'coding', 'debug', 'compile', 'syntax', 'framework', 'library', 'api',
                            'backend', 'frontend', 'fullstack', 'devops', 'git', 'github', 'version', 'control',
                            'python', 'javascript', 'java', 'c++', 'c#', 'php', 'ruby', 'go', 'rust', 'swift',
                            'kotlin', 'typescript', 'html', 'css', 'react', 'angular', 'vue', 'node', 'express',
                            'django', 'flask', 'spring', 'laravel', 'rails', 'docker', 'kubernetes', 'aws',
                            'azure', 'cloud', 'serverless', 'microservice', 'rest', 'graphql', 'sql', 'nosql',
                            'mysql', 'postgresql', 'mongodb', 'redis', 'elasticsearch', 'kafka', 'rabbitmq',
                            'agile', 'scrum', 'kanban', 'cicd', 'testing', 'unit', 'integration', 'qa',
                            'security', 'encryption', 'authentication', 'authorization', 'oauth', 'jwt',
                            'performance', 'optimization', 'scalability', 'architecture', 'design', 'pattern',
                            'solid', 'clean', 'code', 'refactor', 'legacy', 'maintenance', 'documentation',
                            'tutorial', 'example', 'project', 'portfolio', 'bootcamp', 'course', 'certification',
                            'interview', 'leetcode', 'hackerrank', 'competitive', 'programming', 'dsa',
                            'datastructure', 'algorithm', 'complexity', 'bigo', 'recursion', 'dynamic', 'programming'],
            
            'business' => ['management', 'finance', 'marketing', 'startup', 'entrepreneur', 'strategy', 'leadership',
                          'business', 'corporate', 'company', 'enterprise', 'organization', 'venture', 'capital',
                          'investment', 'stock', 'market', 'trading', 'wallstreet', 'banking', 'accounting',
                          'audit', 'tax', 'economy', 'economics', 'macroeconomics', 'microeconomics', 'gdp',
                          'inflation', 'recession', 'growth', 'development', 'planning', 'budget', 'forecast',
                          'analysis', 'analytics', 'kpi', 'metric', 'dashboard', 'report', 'presentation',
                          'pitch', 'deck', 'proposal', 'negotiation', 'deal', 'merger', 'acquisition', 'm&a',
                          'consulting', 'advisory', 'coaching', 'mentoring', 'training', 'workshop', 'seminar',
                          'conference', 'networking', 'relationship', 'partnership', 'collaboration', 'alliance',
                          'brand', 'branding', 'advertising', 'promotion', 'campaign', 'social', 'media',
                          'digital', 'content', 'seo', 'sem', 'ppc', 'cpc', 'cpm', 'roi', 'conversion', 'sales',
                          'selling', 'negotiation', 'customer', 'client', 'user', 'consumer', 'market', 'research',
                          'competitor', 'analysis', 'swot', 'pest', 'porter', 'five', 'forces', 'business', 'model',
                          'canvas', 'lean', 'mvp', 'product', 'market', 'fit', 'growth', 'hacking', 'viral',
                          'monetization', 'revenue', 'profit', 'loss', 'balance', 'sheet', 'income', 'statement',
                          'cash', 'flow', 'asset', 'liability', 'equity', 'roi', 'roa', 'roe', 'valuation',
                          'ipo', 'public', 'offering', 'private', 'equity', 'venture', 'capitalist', 'angel', 'investor'],
            
            'fiction' => ['novel', 'story', 'character', 'plot', 'fantasy', 'mystery', 'romance', 'fiction',
                         'literature', 'literary', 'classic', 'contemporary', 'modern', 'historical', 'fiction',
                         'science', 'fiction', 'sci-fi', 'fantasy', 'high', 'fantasy', 'urban', 'fantasy',
                         'magic', 'wizard', 'witch', 'dragon', 'elf', 'dwarf', 'orc', 'goblin', 'kingdom',
                         'empire', 'realm', 'worldbuilding', 'mythology', 'legend', 'folklore', 'fairy', 'tale',
                         'adventure', 'quest', 'journey', 'hero', 'heroine', 'protagonist', 'antagonist',
                         'villain', 'antihero', 'sidekick', 'companion', 'love', 'interest', 'relationship',
                         'dialogue', 'monologue', 'narration', 'perspective', 'point', 'view', 'first', 'person',
                         'third', 'person', 'omniscient', 'limited', 'unreliable', 'narrator', 'setting',
                         'world', 'atmosphere', 'mood', 'tone', 'theme', 'symbolism', 'allegory', 'metaphor',
                         'simile', 'imagery', 'description', 'prose', 'poetry', 'verse', 'rhyme', 'meter',
                         'stanza', 'sonnet', 'haiku', 'limerick', 'short', 'story', 'novella', 'epic', 'saga',
                         'series', 'trilogy', 'tetralogy', 'pentalogy', 'anthology', 'collection', 'compilation',
                         'bestseller', 'award', 'winning', 'prize', 'nobel', 'pulitzer', 'booker', 'hugo', 'nebula'],
            
            'academic' => ['study', 'research', 'theory', 'methodology', 'analysis', 'scholarly', 'academic',
                          'scholar', 'professor', 'doctorate', 'phd', 'master', 'bachelor', 'degree', 'diploma',
                          'certificate', 'thesis', 'dissertation', 'paper', 'publication', 'journal', 'article',
                          'conference', 'proceedings', 'citation', 'reference', 'bibliography', 'footnote',
                          'endnote', 'abstract', 'summary', 'introduction', 'background', 'literature', 'review',
                          'hypothesis', 'experiment', 'laboratory', 'lab', 'data', 'collection', 'survey',
                          'questionnaire', 'interview', 'observation', 'participant', 'sample', 'population',
                          'statistic', 'statistical', 'analysis', 'quantitative', 'qualitative', 'mixed', 'methods',
                          'variable', 'independent', 'dependent', 'control', 'confounding', 'validity', 'reliability',
                          'significance', 'p-value', 'correlation', 'causation', 'regression', 'anova', 't-test',
                          'chi-square', 'mean', 'median', 'mode', 'standard', 'deviation', 'variance', 'distribution',
                          'normal', 'curve', 'bell', 'curve', 'hypothesis', 'testing', 'null', 'alternative',
                          'peer', 'review', 'blind', 'double-blind', 'placebo', 'control', 'group', 'treatment',
                          'intervention', 'outcome', 'measure', 'instrument', 'scale', 'index', 'inventory',
                          'questionnaire', 'survey', 'poll', 'census', 'demographic', 'psychographic', 'behavioral'],
            
            'self_help' => ['improvement', 'motivation', 'habits', 'success', 'mindset', 'productivity',
                           'self-help', 'personal', 'development', 'growth', 'transform', 'transformation',
                           'change', 'behavior', 'psychology', 'mental', 'health', 'wellness', 'wellbeing',
                           'happiness', 'joy', 'fulfillment', 'satisfaction', 'purpose', 'meaning', 'passion',
                           'goal', 'setting', 'achievement', 'accomplishment', 'success', 'wealth', 'abundance',
                           'prosperity', 'financial', 'freedom', 'independence', 'retirement', 'early', 'retirement',
                           'minimalism', 'simplicity', 'declutter', 'organize', 'clean', 'tidy', 'mariekondo',
                           'mindfulness', 'meditation', 'yoga', 'breathing', 'relaxation', 'stress', 'management',
                           'anxiety', 'depression', 'therapy', 'counseling', 'coaching', 'mentoring', 'guidance',
                           'advice', 'tip', 'technique', 'strategy', 'method', 'approach', 'system', 'routine',
                           'ritual', 'habit', 'discipline', 'willpower', 'self-control', 'focus', 'concentration',
                           'attention', 'memory', 'learning', 'study', 'skill', 'mastery', 'expertise', 'talent',
                           'gift', 'strength', 'weakness', 'opportunity', 'threat', 'swot', 'analysis', 'personal'],
            
            'technical' => ['manual', 'guide', 'tutorial', 'reference', 'handbook', 'textbook', 'technical',
                           'technology', 'engineering', 'mechanical', 'electrical', 'electronic', 'computer',
                           'hardware', 'software', 'firmware', 'embedded', 'system', 'operating', 'system',
                           'linux', 'windows', 'macos', 'unix', 'kernel', 'driver', 'device', 'peripheral',
                           'network', 'networking', 'protocol', 'tcp', 'ip', 'http', 'https', 'ftp', 'ssh',
                           'ssl', 'tls', 'encryption', 'cryptography', 'blockchain', 'bitcoin', 'cryptocurrency',
                           'nft', 'metaverse', 'vr', 'ar', 'virtual', 'reality', 'augmented', 'reality',
                           'ai', 'artificial', 'intelligence', 'machine', 'learning', 'deep', 'learning',
                           'neural', 'network', 'nlp', 'natural', 'language', 'processing', 'computer', 'vision',
                           'robotics', 'automation', 'iot', 'internet', 'things', 'sensor', 'actuator',
                           'controller', 'plc', 'scada', 'hmi', 'cad', 'cam', '3d', 'printing', 'additive',
                           'manufacturing', 'cnc', 'machining', 'tool', 'equipment', 'instrument', 'measurement',
                           'calibration', 'maintenance', 'repair', 'troubleshoot', 'diagnose', 'fault', 'error',
                           'bug', 'issue', 'problem', 'solution', 'fix', 'patch', 'update', 'upgrade', 'version'],
            
            'arts' => ['art', 'painting', 'drawing', 'sketch', 'illustration', 'design', 'graphic', 'digital',
                      'traditional', 'watercolor', 'oil', 'acrylic', 'pastel', 'charcoal', 'pencil', 'pen',
                      'ink', 'brush', 'canvas', 'paper', 'sculpture', 'clay', 'ceramic', 'pottery', 'stone',
                      'marble', 'wood', 'carving', 'whittling', 'metal', 'welding', 'forging', 'smithing',
                      'jewelry', 'bead', 'wire', 'fabric', 'textile', 'weaving', 'knitting', 'crochet',
                      'embroidery', 'sewing', 'quilting', 'pattern', 'fashion', 'clothing', 'apparel',
                      'costume', 'theater', 'drama', 'acting', 'performance', 'stage', 'set', 'design',
                      'lighting', 'sound', 'music', 'instrument', 'piano', 'guitar', 'violin', 'drums',
                      'orchestra', 'symphony', 'concert', 'recital', 'composition', 'song', 'lyric',
                      'melody', 'harmony', 'rhythm', 'beat', 'tempo', 'pitch', 'tone', 'note', 'chord',
                      'scale', 'key', 'major', 'minor', 'photography', 'camera', 'lens', 'aperture',
                      'shutter', 'iso', 'exposure', 'composition', 'rule', 'thirds', 'lighting', 'natural',
                      'studio', 'portrait', 'landscape', 'wildlife', 'macro', 'street', 'documentary',
                      'film', 'cinema', 'movie', 'director', 'producer', 'screenplay', 'script', 'dialogue',
                      'actor', 'actress', 'cinematography', 'editing', 'post-production', 'vfx', 'cg',
                      'animation', '2d', '3d', 'stop-motion', 'claymation', 'pixar', 'disney', 'studio'],
            
            'science' => ['science', 'scientific', 'physics', 'chemistry', 'biology', 'geology', 'astronomy',
                         'mathematics', 'math', 'algebra', 'geometry', 'calculus', 'trigonometry', 'statistics',
                         'probability', 'theory', 'experiment', 'laboratory', 'research', 'discovery',
                         'invention', 'innovation', 'technology', 'engineering', 'scientist', 'researcher',
                         'physicist', 'chemist', 'biologist', 'geologist', 'astronomer', 'mathematician',
                         'quantum', 'relativity', 'particle', 'atom', 'molecule', 'element', 'compound',
                         'reaction', 'chemical', 'organic', 'inorganic', 'biochemistry', 'genetics', 'dna',
                         'rna', 'protein', 'cell', 'organism', 'ecosystem', 'environment', 'climate',
                         'weather', 'geography', 'map', 'cartography', 'universe', 'galaxy', 'star',
                         'planet', 'solar', 'system', 'black', 'hole', 'nebula', 'cosmos', 'space',
                         'rocket', 'satellite', 'telescope', 'microscope', 'spectrometer', 'chromatograph'],
            
            'history' => ['history', 'historical', 'ancient', 'medieval', 'renaissance', 'modern',
                         'contemporary', 'war', 'peace', 'revolution', 'empire', 'kingdom', 'dynasty',
                         'civilization', 'culture', 'society', 'archaeology', 'artifact', 'ruin',
                         'excavation', 'fossil', 'document', 'manuscript', 'scroll', 'papyrus',
                         'chronicle', 'annal', 'biography', 'autobiography', 'memoir', 'diary',
                         'letter', 'correspondence', 'archive', 'library', 'museum', 'gallery',
                         'exhibition', 'collection', 'curator', 'historian', 'archivist', 'palaeontologist',
                         'anthropology', 'ethnography', 'sociology', 'philosophy', 'religion', 'theology',
                         'mythology', 'legend', 'folklore', 'tradition', 'custom', 'ritual', 'ceremony'],
            
            'health' => ['health', 'medical', 'medicine', 'doctor', 'nurse', 'patient', 'hospital',
                        'clinic', 'pharmacy', 'drug', 'medication', 'prescription', 'treatment',
                        'therapy', 'surgery', 'operation', 'diagnosis', 'prognosis', 'symptom',
                        'disease', 'illness', 'condition', 'disorder', 'syndrome', 'infection',
                        'virus', 'bacteria', 'fungus', 'parasite', 'immune', 'system', 'vaccine',
                        'immunization', 'prevention', 'wellness', 'fitness', 'exercise', 'workout',
                        'gym', 'yoga', 'pilates', 'cardio', 'strength', 'training', 'weight',
                        'nutrition', 'diet', 'food', 'meal', 'supplement', 'vitamin', 'mineral',
                        'protein', 'carbohydrate', 'fat', 'calorie', 'metabolism', 'digestion',
                        'sleep', 'rest', 'recovery', 'rehabilitation', 'physiotherapy', 'occupational'],
            
            'travel' => ['travel', 'tourism', 'tourist', 'vacation', 'holiday', 'trip', 'journey',
                        'adventure', 'exploration', 'destination', 'itinerary', 'sightseeing',
                        'landmark', 'attraction', 'monument', 'museum', 'gallery', 'park', 'garden',
                        'beach', 'mountain', 'forest', 'jungle', 'desert', 'island', 'coast',
                        'city', 'town', 'village', 'country', 'nation', 'continent', 'region',
                        'culture', 'local', 'traditional', 'cuisine', 'food', 'restaurant', 'cafe',
                        'hotel', 'hostel', 'resort', 'accommodation', 'transportation', 'flight',
                        'airline', 'airport', 'train', 'railway', 'station', 'bus', 'coach',
                        'car', 'rental', 'cruise', 'ship', 'boat', 'ferry', 'backpack', 'luggage',
                        'passport', 'visa', 'immigration', 'customs', 'currency', 'exchange',
                        'language', 'translation', 'guidebook', 'map', 'navigation', 'gps'],
            
            'cooking' => ['cooking', 'cuisine', 'food', 'recipe', 'ingredient', 'spice', 'herb',
                         'flavor', 'taste', 'aroma', 'scent', 'smell', 'texture', 'consistency',
                         'meal', 'dish', 'course', 'appetizer', 'entree', 'main', 'dessert',
                         'baking', 'pastry', 'bread', 'cake', 'pie', 'cookie', 'biscuit',
                         'chocolate', 'candy', 'sweet', 'savory', 'salty', 'sour', 'bitter',
                         'umami', 'grilling', 'barbecue', 'bbq', 'roasting', 'frying', 'sauteing',
                         'boiling', 'steaming', 'simmering', 'braising', 'stewing', 'marinating',
                         'fermenting', 'pickling', 'preserving', 'canning', 'jarring', 'bottling',
                         'kitchen', 'utensil', 'tool', 'equipment', 'appliance', 'oven', 'stove',
                         'microwave', 'refrigerator', 'freezer', 'blender', 'mixer', 'processor',
                         'knife', 'cutting', 'chopping', 'slicing', 'dicing', 'mincing', 'peeling'],
            
            'sports' => ['sport', 'athletic', 'athlete', 'team', 'game', 'match', 'competition',
                        'tournament', 'championship', 'league', 'season', 'playoff', 'final',
                        'winner', 'champion', 'medal', 'trophy', 'cup', 'award', 'record',
                        'score', 'point', 'goal', 'touchdown', 'home run', 'basket', 'try',
                        'soccer', 'football', 'basketball', 'baseball', 'tennis', 'golf',
                        'cricket', 'rugby', 'hockey', 'volleyball', 'badminton', 'table tennis',
                        'swimming', 'diving', 'gymnastics', 'athletics', 'track', 'field',
                        'marathon', 'triathlon', 'cycling', 'biking', 'skiing', 'snowboarding',
                        'skating', 'surfing', 'sailing', 'rowing', 'boxing', 'martial', 'arts',
                        'karate', 'judo', 'taekwondo', 'wrestling', 'weightlifting', 'powerlifting',
                        'fitness', 'exercise', 'workout', 'training', 'coach', 'trainer', 'umpire', 'referee'],
            
            'education' => ['education', 'learning', 'teaching', 'instruction', 'pedagogy',
                           'curriculum', 'syllabus', 'lesson', 'lecture', 'class', 'course',
                           'subject', 'discipline', 'major', 'minor', 'elective', 'requirement',
                           'grade', 'mark', 'score', 'test', 'exam', 'quiz', 'assessment',
                           'evaluation', 'assignment', 'homework', 'project', 'paper', 'essay',
                           'thesis', 'dissertation', 'research', 'study', 'scholarship', 'grant',
                           'bursary', 'loan', 'tuition', 'fee', 'cost', 'expense', 'campus',
                           'university', 'college', 'school', 'institute', 'academy', 'faculty',
                           'department', 'division', 'professor', 'lecturer', 'teacher', 'instructor',
                           'tutor', 'mentor', 'advisor', 'counselor', 'principal', 'dean', 'director',
                           'student', 'pupil', 'learner', 'alumni', 'graduate', 'undergraduate',
                           'postgraduate', 'doctoral', 'phd', 'master', 'bachelor', 'associate'],
            
            'psychology' => ['psychology', 'psychological', 'mind', 'brain', 'cognition', 'thought',
                            'emotion', 'feeling', 'mood', 'personality', 'trait', 'character',
                            'behavior', 'action', 'reaction', 'response', 'stimulus', 'perception',
                            'sensation', 'attention', 'memory', 'learning', 'intelligence', 'iq',
                            'creativity', 'imagination', 'dream', 'consciousness', 'subconscious',
                            'unconscious', 'freud', 'jung', 'behaviorism', 'cognitive', 'humanistic',
                            'developmental', 'child', 'adult', 'aging', 'gerontology', 'social',
                            'group', 'community', 'society', 'culture', 'interpersonal', 'relationship',
                            'communication', 'language', 'speech', 'disorder', 'therapy', 'counseling',
                            'clinical', 'abnormal', 'diagnosis', 'dsm', 'manual', 'disorder', 'syndrome',
                            'depression', 'anxiety', 'stress', 'ptsd', 'ocd', 'adhd', 'autism', 'spectrum'],
            
            'philosophy' => ['philosophy', 'philosophical', 'thought', 'idea', 'concept', 'theory',
                            'principle', 'doctrine', 'belief', 'value', 'ethics', 'morality',
                            'good', 'evil', 'right', 'wrong', 'justice', 'fairness', 'equality',
                            'freedom', 'liberty', 'democracy', 'authority', 'power', 'government',
                            'state', 'society', 'community', 'individual', 'self', 'identity',
                            'existence', 'being', 'reality', 'truth', 'knowledge', 'epistemology',
                            'metaphysics', 'ontology', 'logic', 'reason', 'argument', 'premise',
                            'conclusion', 'fallacy', 'sophistry', 'rhetoric', 'dialectic', 'debate',
                            'discussion', 'dialogue', 'socrates', 'plato', 'aristotle', 'descartes',
                            'kant', 'hegel', 'nietzsche', 'existentialism', 'stoicism', 'epicureanism',
                            'utilitarianism', 'consequentialism', 'deontology', 'virtue', 'ethics'],
            
            'religion' => ['religion', 'religious', 'spiritual', 'faith', 'belief', 'god', 'deity',
                          'divine', 'sacred', 'holy', 'bible', 'scripture', 'gospel', 'testament',
                          'quran', 'koran', 'torah', 'talmud', 'veda', 'sutra', 'teaching',
                          'doctrine', 'dogma', 'creed', 'tenet', 'principle', 'practice', 'ritual',
                          'ceremony', 'worship', 'prayer', 'meditation', 'contemplation', 'chant',
                          'hymn', 'psalm', 'sermon', 'homily', 'preach', 'prophet', 'messiah',
                          'savior', 'redeemer', 'priest', 'minister', 'rabbi', 'imam', 'monk',
                          'nun', 'clergy', 'laity', 'congregation', 'church', 'temple', 'mosque',
                          'synagogue', 'shrine', 'altar', 'icon', 'idol', 'symbol', 'cross',
                          'crescent', 'star', 'david', 'om', 'yin', 'yang', 'karma', 'dharma',
                          'nirvana', 'enlightenment', 'salvation', 'redemption', 'heaven', 'hell'],
            
            'law' => ['law', 'legal', 'justice', 'court', 'judge', 'jury', 'trial', 'case',
                     'lawsuit', 'litigation', 'plaintiff', 'defendant', 'prosecutor', 'defense',
                     'attorney', 'lawyer', 'solicitor', 'barrister', 'advocate', 'counsel',
                     'paralegal', 'clerk', 'bailiff', 'sheriff', 'police', 'officer', 'detective',
                     'investigator', 'evidence', 'testimony', 'witness', 'expert', 'deposition',
                     'affidavit', 'subpoena', 'summons', 'warrant', 'arrest', 'charge', 'indictment',
                     'conviction', 'sentence', 'penalty', 'fine', 'prison', 'jail', 'probation',
                     'parole', 'appeal', 'verdict', 'judgment', 'ruling', 'order', 'decree',
                     'statute', 'regulation', 'ordinance', 'constitution', 'amendment', 'bill',
                     'act', 'treaty', 'contract', 'agreement', 'lease', 'deed', 'will', 'trust',
                     'estate', 'property', 'real', 'personal', 'intellectual', 'patent', 'copyright',
                     'trademark', 'corporate', 'business', 'tax', 'immigration', 'family', 'criminal'],
            
            'politics' => ['politics', 'political', 'government', 'state', 'nation', 'country',
                          'democracy', 'republic', 'monarchy', 'dictatorship', 'totalitarian',
                          'authoritarian', 'liberal', 'conservative', 'socialist', 'communist',
                          'capitalist', 'libertarian', 'anarchist', 'fascist', 'nationalist',
                          'internationalist', 'globalist', 'isolationist', 'interventionist',
                          'diplomacy', 'foreign', 'policy', 'domestic', 'internal', 'affairs',
                          'election', 'vote', 'ballot', 'candidate', 'campaign', 'rally',
                          'debate', 'speech', 'promise', 'platform', 'manifesto', 'party',
                          'coalition', 'opposition', 'majority', 'minority', 'senate', 'congress',
                          'parliament', 'assembly', 'legislature', 'executive', 'president',
                          'prime', 'minister', 'chancellor', 'governor', 'mayor', 'bureaucracy',
                          'administration', 'agency', 'department', 'ministry', 'office', 'official'],
            
            'economics' => ['economics', 'economic', 'economy', 'market', 'trade', 'commerce',
                           'business', 'industry', 'sector', 'production', 'consumption',
                           'supply', 'demand', 'price', 'cost', 'value', 'profit', 'loss',
                           'revenue', 'income', 'expense', 'debt', 'credit', 'loan', 'interest',
                           'investment', 'capital', 'asset', 'liability', 'equity', 'stock',
                           'bond', 'share', 'dividend', 'portfolio', 'fund', 'mutual', 'etf',
                           'derivative', 'option', 'future', 'hedge', 'speculation', 'arbitrage',
                           'inflation', 'deflation', 'recession', 'depression', 'growth', 'development',
                           'gdp', 'gross', 'domestic', 'product', 'gnp', 'national', 'unemployment',
                           'employment', 'labor', 'workforce', 'productivity', 'efficiency', 'innovation',
                           'technology', 'globalization', 'protectionism', 'tariff', 'quota', 'subsidy',
                           'tax', 'revenue', 'expenditure', 'budget', 'deficit', 'surplus', 'balance']
        ];
    }
    
    private function initializeMisspellingCorrections() {
        // 200+ common misspellings and their corrections
        $this->misspellingCorrections = [
            // Arts related misspellings
            'art' => ['arts', 'artss', 'arte', 'arrt', 'artt', 'aart', 'arty', 'arth'],
            'painting' => ['panting', 'paintng', 'paintig', 'paintin', 'paynting', 'paintning', 'paintign'],
            'drawing' => ['drawin', 'draing', 'drowing', 'drawring', 'drawwing', 'dawing', 'darwing'],
            'sketch' => ['scketch', 'skethc', 'skech', 'skecth', 'sketche', 'sketh', 'skach'],
            'illustration' => ['ilustration', 'illustracion', 'illustraton', 'ilustracion', 'illustrashun', 'illustratin'],
            'design' => ['desing', 'desgn', 'dessign', 'designe', 'dezign', 'desin', 'degign'],
            'graphic' => ['grafic', 'graphik', 'graphicc', 'grafik', 'graphick', 'grapic', 'graphix'],
            'digital' => ['digial', 'digitel', 'digatal', 'digitl', 'dijital', 'digitel', 'digitial'],
            
            // Programming misspellings
            'programming' => ['programing', 'programmig', 'programin', 'proggramming', 'progamming', 'programmingg'],
            'software' => ['sofware', 'softwere', 'softwear', 'sooftware', 'softwaree', 'sowftware'],
            'developer' => ['develper', 'developr', 'develoer', 'devloper', 'develpoer', 'developre'],
            'algorithm' => ['algoritm', 'algorith', 'algorithim', 'alghorithm', 'algorithhm', 'algorithem'],
            'database' => ['databse', 'data base', 'data-base', 'databaze', 'databes', 'databas'],
            'javascript' => ['javascrip', 'javscript', 'javascrpt', 'javascriptt', 'java-script', 'jaavscript'],
            'python' => ['pyton', 'pythn', 'pythoon', 'pyhton', 'pythin', 'pythoon'],
            'java' => ['jva', 'jaav', 'jaba', 'javva', 'javaa', 'jave'],
            
            // Business misspellings
            'management' => ['managment', 'managemant', 'manegement', 'mangement', 'managemen', 'managmentt'],
            'finance' => ['finace', 'finnance', 'finanse', 'fiannce', 'finnanse', 'finence'],
            'marketing' => ['marketting', 'markting', 'marketin', 'marketng', 'marketeing', 'marketign'],
            'business' => ['bussiness', 'buisness', 'busness', 'bussines', 'buisnes', 'bussines'],
            'entrepreneur' => ['entreprenuer', 'entrepeneur', 'entreprenur', 'entrepeneur', 'entreprenuer', 'entreprenour'],
            'strategy' => ['stratgy', 'stategy', 'strategi', 'stratejy', 'stratergy', 'strategie'],
            
            // Fiction misspellings
            'fiction' => ['ficton', 'ficion', 'fictin', 'ficktion', 'ficction', 'fictioon'],
            'novel' => ['novell', 'novle', 'noveel', 'novvel', 'novelll', 'nofel'],
            'story' => ['storey', 'storry', 'stoy', 'storie', 'storee', 'stary'],
            'character' => ['charcter', 'caracter', 'charecter', 'charachter', 'chracter', 'charactar'],
            'fantasy' => ['fantazy', 'fantasey', 'phantasy', 'fantacy', 'fantsay', 'fantazy'],
            'mystery' => ['mistery', 'mystry', 'mysterie', 'mistrey', 'mysteri', 'mysterry'],
            'romance' => ['rommance', 'romence', 'romanse', 'rommense', 'romence', 'romanse'],
            
            // Academic misspellings
            'academic' => ['acadmic', 'acadmiec', 'acadmeic', 'acadimic', 'accademic', 'acaddemic'],
            'research' => ['reserch', 'reaseach', 'reasearch', 'researh', 'reseacrh', 'researhc'],
            'theory' => ['theroy', 'tehory', 'theori', 'theery', 'theorry', 'theoriy'],
            'methodology' => ['methodolgy', 'methodoligy', 'methadology', 'methodolgy', 'methodolog', 'methodolagy'],
            'analysis' => ['analisis', 'anallysis', 'analysys', 'annalysis', 'analysiis', 'anaylsis'],
            'scholarly' => ['scholarli', 'schollarly', 'scholary', 'scholalry', 'schollary', 'scholarlie'],
            
            // Self-help misspellings
            'improvement' => ['improvment', 'improovment', 'improvemnt', 'improvemen', 'improovemen', 'improovmentt'],
            'motivation' => ['motavation', 'motivatn', 'motivaton', 'motivasion', 'mottivation', 'motivition'],
            'productivity' => ['productivty', 'productiviy', 'productivitty', 'productivite', 'productivtyy', 'productivety'],
            'mindset' => ['mindsett', 'mindsit', 'mindsete', 'mindsett', 'mindsette', 'mindset'],
            'success' => ['sucess', 'sucsess', 'succes', 'succeess', 'succsess', 'sucesss'],
            'habits' => ['habbits', 'habitts', 'habites', 'habitts', 'habbitss', 'habbitts'],
            
            // Technical misspellings
            'technical' => ['tecnical', 'techncal', 'techincal', 'tecknical', 'technicl', 'technicall'],
            'manual' => ['manuall', 'manaul', 'mannual', 'manuall', 'mannaul', 'manuaal'],
            'guide' => ['gide', 'guied', 'gudie', 'guilde', 'giude', 'gide'],
            'tutorial' => ['tutrial', 'tutoral', 'tuttorial', 'tutorail', 'tuturial', 'tutorrial'],
            'reference' => ['refernce', 'refrence', 'referance', 'referece', 'refrense', 'referense'],
            'handbook' => ['handbok', 'handboook', 'handbuk', 'handboock', 'handbbok', 'handbooc'],
            
            // Science misspellings
            'science' => ['sciense', 'sciance', 'scienc', 'sciens', 'sciience', 'sciennce'],
            'physics' => ['phisics', 'physicss', 'phyisics', 'physicks', 'physiccs', 'physic'],
            'chemistry' => ['chemisty', 'chemystry', 'chimistry', 'chemistri', 'chemestry', 'chemistrie'],
            'biology' => ['biollogy', 'biolgy', 'bioligy', 'biololgy', 'biollogy', 'bioligy'],
            'mathematics' => ['mathematcs', 'mathmatics', 'mathemetics', 'maths', 'mathemathics', 'mathemtics'],
            'astronomy' => ['astronmy', 'astronomie', 'astronomey', 'astronomi', 'astronomie', 'astronome'],
            
            // History misspellings
            'history' => ['histroy', 'histry', 'histori', 'historey', 'histore', 'historiy'],
            'historical' => ['historicall', 'histroical', 'historcal', 'histrical', 'historicl', 'histroicl'],
            'ancient' => ['ancent', 'anciant', 'ancinet', 'anciet', 'ancint', 'ancientt'],
            'medieval' => ['medival', 'medievel', 'medeival', 'medievil', 'medievale', 'medievil'],
            'renaissance' => ['rennaisance', 'renaisance', 'renaissence', 'rennaissnce', 'renaisanse', 'renaissanse'],
            'modern' => ['modren', 'moddern', 'moden', 'moddern', 'moderm', 'modren'],
            
            // Health misspellings
            'health' => ['healt', 'helth', 'healh', 'helath', 'healthe', 'healtth'],
            'medical' => ['medcal', 'medicl', 'medicall', 'meddical', 'medikal', 'medicel'],
            'medicine' => ['medicin', 'medecine', 'medisine', 'meddicine', 'medicien', 'medisine'],
            'doctor' => ['docter', 'doctar', 'docttor', 'doctir', 'doctur', 'doctar'],
            'hospital' => ['hospitel', 'hospitl', 'hospitale', 'hosptal', 'hospitl', 'hospittal'],
            'fitness' => ['fitnes', 'fittness', 'fitnes', 'fittnes', 'fitniss', 'fitnes'],
            
            // Travel misspellings
            'travel' => ['traval', 'travell', 'travelle', 'travvel', 'traveel', 'travl'],
            'tourism' => ['tourisim', 'turism', 'tourisme', 'turrism', 'tourismm', 'tourisum'],
            'vacation' => ['vaction', 'vacaton', 'vacasion', 'vakation', 'vacashun', 'vacatian'],
            'adventure' => ['adventur', 'adventer', 'adventrue', 'adventer', 'adventuer', 'adventrue'],
            'destination' => ['destinaton', 'destiation', 'destinatin', 'destnation', 'destinashion', 'destinashun'],
            'itinerary' => ['itenerary', 'itinirary', 'itenerery', 'itinirery', 'itinerery', 'itinerari'],
            
            // Cooking misspellings
            'cooking' => ['cookin', 'cookking', 'cookig', 'cookign', 'cookinng', 'cookingg'],
            'recipe' => ['receipe', 'recipee', 'reciepe', 'recipie', 'recep', 'recepy'],
            'ingredient' => ['ingridient', 'ingrediant', 'ingredent', 'ingreedient', 'ingredint', 'ingrediant'],
            'baking' => ['bakin', 'bakeing', 'bakking', 'bakein', 'bakinng', 'bakingg'],
            'grilling' => ['grilin', 'grillng', 'grilign', 'grillnig', 'grillling', 'grillingg'],
            'restaurant' => ['resturant', 'restaraunt', 'restraunt', 'restaurnt', 'restuarant', 'restaraunt'],
            
            // Sports misspellings
            'sports' => ['sportss', 'sporrt', 'sportts', 'spports', 'sport', 'sportes'],
            'athlete' => ['athleet', 'athlet', 'athleete', 'athlethe', 'athlett', 'athleate'],
            'competition' => ['compettion', 'compeition', 'compitition', 'competishun', 'competitin', 'compitishun'],
            'championship' => ['championshipp', 'champoinship', 'championshipe', 'championshep', 'champinship', 'championshipp'],
            'basketball' => ['basketbal', 'baskettball', 'basketbaall', 'baskeball', 'basketabll', 'basketbell'],
            'football' => ['fotball', 'footbal', 'fottball', 'footbaall', 'futball', 'footboll'],
            
            // Education misspellings
            'education' => ['educaton', 'educashion', 'eduction', 'educashun', 'educatin', 'edducatian'],
            'learning' => ['lerning', 'learing', 'learnin', 'learnign', 'leearnin', 'learnning'],
            'teaching' => ['teching', 'teacheing', 'teachin', 'teatchign', 'teacheeng', 'teatchin'],
            'university' => ['universty', 'univrsity', 'univercity', 'universtiy', 'univerity', 'univarsity'],
            'college' => ['collge', 'colege', 'collage', 'collige', 'coledge', 'collige'],
            'student' => ['studnt', 'studant', 'studdent', 'studint', 'studunt', 'studnt'],
            
            // Psychology misspellings
            'psychology' => ['psycology', 'psycholgy', 'psichology', 'psycholigy', 'psychollogy', 'psycollogy'],
            'behavior' => ['behaviour', 'behaivor', 'behavoir', 'behaivour', 'behavir', 'behaver'],
            'personality' => ['personallity', 'personalty', 'personalitie', 'personallitie', 'personaliti', 'personallity'],
            'therapy' => ['theraphy', 'terapy', 'therapi', 'theerapy', 'therapphy', 'therpay'],
            'depression' => ['depresion', 'depresson', 'depresion', 'depressin', 'depresionn', 'depreshun'],
            'anxiety' => ['anxity', 'anxieti', 'anxieaty', 'anxeity', 'anxiity', 'anxeaty'],
            
            // Philosophy misspellings
            'philosophy' => ['philospohy', 'philosofy', 'phillosophy', 'philossophy', 'philosphy', 'phillosofy'],
            'ethics' => ['ethicss', 'ethiks', 'ethicks', 'ethiccs', 'ethix', 'ethycs'],
            'morality' => ['moralitty', 'morallity', 'moralety', 'morallitie', 'moraliti', 'morallity'],
            'justice' => ['justise', 'justise', 'justicce', 'justise', 'justisse', 'justise'],
            'freedom' => ['freedoom', 'freemd', 'freedem', 'freeedom', 'freedum', 'freedim'],
            'knowledge' => ['knowlege', 'knowledg', 'knowlage', 'knowldege', 'knowlegde', 'knowledje'],
            
            // Religion misspellings
            'religion' => ['religon', 'religeon', 'religian', 'religeion', 'relijion', 'religeon'],
            'spiritual' => ['spirital', 'spirtitual', 'spiritual', 'spirituel', 'spirtual', 'spirituall'],
            'faith' => ['faithe', 'feith', 'faeth', 'faithe', 'feath', 'faeth'],
            'prayer' => ['prayr', 'preyer', 'praer', 'prayre', 'preayer', 'praier'],
            'church' => ['chruch', 'churhc', 'churh', 'churhc', 'churche', 'churhc'],
            'temple' => ['temlpe', 'tempel', 'templee', 'tempple', 'tempel', 'temle'],
            
            // Law misspellings
            'law' => ['laaw', 'laww', 'lwa', 'lawe', 'lawa', 'lwa'],
            'legal' => ['leagal', 'legall', 'legel', 'leegal', 'legall', 'legel'],
            'justice' => ['justise', 'justise', 'justicce', 'justise', 'justisse', 'justise'],
            'court' => ['cort', 'cuort', 'courrt', 'courtt', 'coort', 'curt'],
            'attorney' => ['atorney', 'attorny', 'attorne', 'atturney', 'attornie', 'atturne'],
            'evidence' => ['evidance', 'evidense', 'evidnce', 'evidens', 'evidensee', 'evidance'],
            
            // Politics misspellings
            'politics' => ['politicss', 'polotics', 'politicks', 'politiccs', 'politis', 'polotics'],
            'government' => ['goverment', 'govenment', 'governmant', 'govermnent', 'governemnt', 'govermentt'],
            'democracy' => ['democrasy', 'democrazy', 'democracie', 'democrasie', 'democraci', 'democrazy'],
            'election' => ['elecshun', 'eleciton', 'elektion', 'elecshion', 'elektion', 'elecsion'],
            'president' => ['presdent', 'presidnt', 'presidant', 'presidint', 'presedent', 'presidant'],
            'parliament' => ['parliment', 'parlement', 'parlament', 'parlimant', 'parleament', 'parlament'],
            
            // Economics misspellings
            'economics' => ['econmics', 'econimics', 'economcs', 'economicks', 'economix', 'econimiks'],
            'economy' => ['econmy', 'economey', 'ekonomy', 'ecomony', 'econimy', 'ekonmy'],
            'market' => ['markit', 'markett', 'markeet', 'markit', 'markete', 'markete'],
            'finance' => ['finace', 'finnance', 'finanse', 'fiannce', 'finnanse', 'finence'],
            'investment' => ['investmnt', 'investmant', 'invetment', 'investiment', 'investmint', 'invetmint'],
            'inflation' => ['inflaton', 'inflasion', 'inflashion', 'inflashun', 'inflatin', 'inflasion']
        ];
    }
    
    public function intelligentSearch($query, $books, $limit = 20) {
        // Check and correct misspellings first
        $correctedQuery = $this->correctMisspellings($query);
        $originalQuery = $query;
        
        // If query was corrected, use the corrected version
        if ($correctedQuery !== $query) {
            $query = $correctedQuery;
        }
        
        // Log search for pattern learning
        $this->logSearch($originalQuery, $correctedQuery);
        
        if (empty($query) || empty($books)) {
            return $books;
        }
        
        $query = strtolower(trim($query));
        
        // Enhanced AI-powered ranking with multiple factors
        $scoredBooks = [];
        
        foreach ($books as $book) {
            $score = 0;
            
            // Factor 1: Exact matches (highest priority)
            $score += $this->calculateExactMatchScore($query, $book);
            
            // Factor 2: Semantic understanding
            $score += $this->calculateSemanticScore($query, $book);
            
            // Factor 3: Popularity boost
            $score += $this->calculatePopularityScore($book);
            
            // Factor 4: Availability boost
            $score += $this->calculateAvailabilityScore($book);
            
            // Factor 5: Recency boost
            $score += $this->calculateRecencyScore($book);
            
            // Factor 6: User behavior patterns (simulated)
            $score += $this->calculateBehaviorScore($query, $book);
            
            // Factor 7: Misspelling tolerance bonus
            if ($correctedQuery !== $originalQuery) {
                $score += $this->calculateMisspellingTolerance($originalQuery, $book);
            }
            
            if ($score > 0) {
                $scoredBooks[] = [
                    'book' => $book,
                    'score' => $score,
                    'ai_explanation' => $this->generateExplanation($query, $book, $score, $correctedQuery !== $originalQuery)
                ];
            }
        }
        
        // Sort by AI score (descending)
        usort($scoredBooks, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        // Return only books
        $result = array_map(function($item) {
            return $item['book'];
        }, array_slice($scoredBooks, 0, $limit));
        
        return $result;
    }
    
    private function calculateExactMatchScore($query, $book) {
        $score = 0;
        
        // Check title
        $title = strtolower($book['title'] ?? '');
        if (strpos($title, $query) !== false) {
            $score += 100;
        }
        
        // Check author
        $author = strtolower($book['author'] ?? '');
        if (strpos($author, $query) !== false) {
            $score += 80;
        }
        
        // Check ISBN
        $isbn = strtolower($book['isbn'] ?? '');
        if (strpos($isbn, $query) !== false) {
            $score += 100;
        }
        
        // Check category
        $category = strtolower($book['category'] ?? '');
        if (strpos($category, $query) !== false) {
            $score += 60;
        }
        
        // Check publisher
        $publisher = strtolower($book['publisher'] ?? '');
        if (strpos($publisher, $query) !== false) {
            $score += 40;
        }
        
        // Check description
        $description = strtolower($book['description'] ?? '');
        if (strpos($description, $query) !== false) {
            $score += 30;
        }
        
        return $score;
    }
    
    private function calculateSemanticScore($query, $book) {
        $score = 0;
        
        // Analyze query type
        $queryType = $this->analyzeQueryType($query);
        $bookType = $this->analyzeBookType($book);
        
        // Bonus for semantic match
        if ($queryType === $bookType) {
            $score += 50;
        }
        
        // Check for related terms
        $relatedTerms = $this->getRelatedTerms($query);
        foreach ($relatedTerms as $term) {
            $title = strtolower($book['title'] ?? '');
            $description = strtolower($book['description'] ?? '');
            
            if (strpos($title, $term) !== false) {
                $score += 20;
            }
            if (strpos($description, $term) !== false) {
                $score += 10;
            }
        }
        
        // Check for conceptual matches
        if ($this->isConceptualMatch($query, $book)) {
            $score += 40;
        }
        
        return $score;
    }
    
    private function calculatePopularityScore($book) {
        $score = 0;
        
        // Books with more copies are likely more popular
        $totalCopies = $book['total_copies_cache'] ?? 0;
        if ($totalCopies > 10) {
            $score += 20;
        } elseif ($totalCopies > 5) {
            $score += 10;
        }
        
        // Newer books might be more popular
        $year = $book['year_published'] ?? 0;
        if ($year >= 2020) {
            $score += 15;
        } elseif ($year >= 2010) {
            $score += 10;
        }
        
        return $score;
    }
    
    private function calculateAvailabilityScore($book) {
        $score = 0;
        
        // Boost available books
        $available = $book['available_copies_cache'] ?? 0;
        if ($available > 0) {
            $score += 25;
        }
        
        // Extra boost for multiple available copies
        if ($available > 3) {
            $score += 15;
        }
        
        return $score;
    }
    
    private function calculateRecencyScore($book) {
        $score = 0;
        
        // Recent publications get a boost
        $year = $book['year_published'] ?? 0;
        $currentYear = date('Y');
        
        if ($year >= ($currentYear - 2)) {
            $score += 30;
        } elseif ($year >= ($currentYear - 5)) {
            $score += 20;
        } elseif ($year >= ($currentYear - 10)) {
            $score += 10;
        }
        
        return $score;
    }
    
    private function calculateBehaviorScore($query, $book) {
        $score = 0;
        
        // Simulate learning from user behavior
        // In a real system, this would analyze actual user interactions
        
        // Common patterns: programming books often searched together
        if (strpos($query, 'programming') !== false || 
            strpos($query, 'coding') !== false ||
            strpos($query, 'software') !== false) {
            $category = strtolower($book['category'] ?? '');
            if (strpos($category, 'information technology') !== false ||
                strpos($category, 'computer') !== false) {
                $score += 25;
            }
        }
        
        // Business books pattern
        if (strpos($query, 'business') !== false ||
            strpos($query, 'management') !== false ||
            strpos($query, 'finance') !== false) {
            $category = strtolower($book['category'] ?? '');
            if (strpos($category, 'business') !== false) {
                $score += 25;
            }
        }
        
        // Arts pattern
        if (strpos($query, 'art') !== false ||
            strpos($query, 'painting') !== false ||
            strpos($query, 'drawing') !== false) {
            $category = strtolower($book['category'] ?? '');
            if (strpos($category, 'art') !== false ||
                strpos($category, 'design') !== false ||
                strpos($category, 'creative') !== false) {
                $score += 25;
            }
        }
        
        return $score;
    }
    
    private function calculateMisspellingTolerance($originalQuery, $book) {
        $score = 0;
        $originalQuery = strtolower(trim($originalQuery));
        
        // Check if original misspelling appears in any field
        $title = strtolower($book['title'] ?? '');
        $author = strtolower($book['author'] ?? '');
        $description = strtolower($book['description'] ?? '');
        
        // If the misspelling appears, give a small bonus for tolerance
        if (strpos($title, $originalQuery) !== false) {
            $score += 15;
        }
        if (strpos($author, $originalQuery) !== false) {
            $score += 10;
        }
        if (strpos($description, $originalQuery) !== false) {
            $score += 5;
        }
        
        return $score;
    }
    
    private function correctMisspellings($query) {
        $query = strtolower(trim($query));
        $words = explode(' ', $query);
        $correctedWords = [];
        
        foreach ($words as $word) {
            $correctedWord = $word;
            
            // Check if word is misspelled
            foreach ($this->misspellingCorrections as $correct => $misspellings) {
                if (in_array($word, $misspellings)) {
                    $correctedWord = $correct;
                    break;
                }
            }
            
            // Also check for close matches using Levenshtein distance
            if ($correctedWord === $word && strlen($word) > 2) {
                foreach ($this->misspellingCorrections as $correct => $misspellings) {
                    // Check if word is similar to any correct word
                    $similarity = levenshtein($word, $correct);
                    if ($similarity <= 2 && $similarity > 0) {
                        $correctedWord = $correct;
                        break;
                    }
                }
            }
            
            $correctedWords[] = $correctedWord;
        }
        
        $correctedQuery = implode(' ', $correctedWords);
        
        // Return original if no corrections made
        return $correctedQuery !== $query ? $correctedQuery : $query;
    }
    
    private function analyzeQueryType($query) {
        $query = strtolower($query);
        
        if (strpos($query, 'how to') !== false ||
            strpos($query, 'tutorial') !== false ||
            strpos($query, 'guide') !== false) {
            return 'instructional';
        }
        
        if (strpos($query, 'best') !== false ||
            strpos($query, 'top') !== false ||
            strpos($query, 'recommend') !== false) {
            return 'recommendation';
        }
        
        if (strpos($query, 'introduction') !== false ||
            strpos($query, 'beginner') !== false ||
            strpos($query, 'basics') !== false) {
            return 'introductory';
        }
        
        if (strpos($query, 'advanced') !== false ||
            strpos($query, 'expert') !== false ||
            strpos($query, 'master') !== false) {
            return 'advanced';
        }
        
        return 'general';
    }
    
    private function analyzeBookType($book) {
        $title = strtolower($book['title'] ?? '');
        $description = strtolower($book['description'] ?? '');
        
        if (strpos($title, 'introduction') !== false ||
            strpos($title, 'beginner') !== false ||
            strpos($title, 'basics') !== false ||
            strpos($description, 'beginner') !== false) {
            return 'introductory';
        }
        
        if (strpos($title, 'advanced') !== false ||
            strpos($title, 'expert') !== false ||
            strpos($title, 'master') !== false ||
            strpos($description, 'advanced') !== false) {
            return 'advanced';
        }
        
        if (strpos($title, 'guide') !== false ||
            strpos($title, 'handbook') !== false ||
            strpos($title, 'manual') !== false ||
            strpos($description, 'step-by-step') !== false) {
            return 'instructional';
        }
        
        return 'general';
    }
    
    private function getRelatedTerms($query) {
        $query = strtolower($query);
        $related = [];
        
        // Check all semantic patterns for matches
        foreach ($this->semanticPatterns as $category => $terms) {
            foreach ($terms as $term) {
                if (strpos($query, $term) !== false) {
                    // Add other terms from the same category
                    $related = array_merge($related, array_slice($terms, 0, 10));
                    break;
                }
            }
        }
        
        // Programming related
        if (strpos($query, 'programming') !== false) {
            $related = array_merge($related, ['code', 'software', 'developer', 'coding', 'algorithm']);
        }
        
        if (strpos($query, 'database') !== false) {
            $related = array_merge($related, ['sql', 'mysql', 'mongodb', 'data', 'storage']);
        }
        
        // Arts related
        if (strpos($query, 'art') !== false) {
            $related = array_merge($related, ['painting', 'drawing', 'design', 'creative', 'visual']);
        }
        
        if (strpos($query, 'music') !== false) {
            $related = array_merge($related, ['instrument', 'song', 'melody', 'harmony', 'rhythm']);
        }
        
        // Business related
        if (strpos($query, 'business') !== false) {
            $related = array_merge($related, ['management', 'finance', 'marketing', 'strategy']);
        }
        
        if (strpos($query, 'money') !== false) {
            $related = array_merge($related, ['finance', 'wealth', 'investment', 'economics']);
        }
        
        // Learning related
        if (strpos($query, 'learn') !== false) {
            $related = array_merge($related, ['study', 'education', 'knowledge', 'skill']);
        }
        
        return array_unique($related);
    }
    
    private function isConceptualMatch($query, $book) {
        $query = strtolower($query);
        $title = strtolower($book['title'] ?? '');
        $description = strtolower($book['description'] ?? '');
        
        // Check for conceptual matches (e.g., "data storage" matches "database")
        $conceptualPairs = [
            'data storage' => ['database', 'sql', 'mongodb', 'redis'],
            'web development' => ['html', 'css', 'javascript', 'react', 'vue'],
            'mobile app' => ['android', 'ios', 'react native', 'flutter'],
            'machine learning' => ['ai', 'artificial intelligence', 'neural network'],
            'cloud computing' => ['aws', 'azure', 'google cloud', 'serverless'],
            'software engineering' => ['clean code', 'design patterns', 'architecture'],
            'art design' => ['painting', 'drawing', 'sketching', 'illustration'],
            'music composition' => ['song', 'melody', 'harmony', 'rhythm'],
            'business management' => ['leadership', 'strategy', 'administration', 'organization'],
            'health fitness' => ['exercise', 'workout', 'nutrition', 'wellness'],
        ];
        
        foreach ($conceptualPairs as $concept => $terms) {
            if (strpos($query, $concept) !== false) {
                foreach ($terms as $term) {
                    if (strpos($title, $term) !== false || strpos($description, $term) !== false) {
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    private function generateExplanation($query, $book, $score, $wasCorrected = false) {
        $reasons = [];
        
        // Generate human-readable explanation for the ranking
        $title = strtolower($book['title'] ?? '');
        $query = strtolower($query);
        
        if ($wasCorrected) {
            $reasons[] = "Showing results for corrected spelling";
        }
        
        if (strpos($title, $query) !== false) {
            $reasons[] = "Title contains your search term";
        }
        
        $available = $book['available_copies_cache'] ?? 0;
        if ($available > 0) {
            $reasons[] = "Available for immediate borrowing";
        }
        
        $year = $book['year_published'] ?? 0;
        if ($year >= 2020) {
            $reasons[] = "Recent publication";
        }
        
        $totalCopies = $book['total_copies_cache'] ?? 0;
        if ($totalCopies > 5) {
            $reasons[] = "Popular title in our collection";
        }
        
        // Check for semantic match
        foreach ($this->semanticPatterns as $category => $terms) {
            foreach ($terms as $term) {
                if (strpos($query, $term) !== false) {
                    $reasons[] = "Matches $category category";
                    break 2;
                }
            }
        }
        
        return implode('; ', $reasons);
    }
    
    private function logSearch($originalQuery, $correctedQuery = null) {
        // In a real system, this would log to a database for ML training
        // For now, just keep in memory for this session
        $this->searchHistory[] = [
            'original_query' => $originalQuery,
            'corrected_query' => $correctedQuery,
            'timestamp' => time(),
            'session_id' => session_id(),
            'was_corrected' => $correctedQuery !== null && $correctedQuery !== $originalQuery
        ];
        
        // Keep only last 100 searches
        if (count($this->searchHistory) > 100) {
            array_shift($this->searchHistory);
        }
    }
    
    public function getSearchInsights() {
        return [
            'total_searches' => count($this->searchHistory),
            'recent_queries' => array_slice(array_column($this->searchHistory, 'original_query'), -10),
            'ai_model' => $this->aiModel,
            'patterns_learned' => count($this->semanticPatterns),
            'misspelling_corrections' => count($this->misspellingCorrections),
            'correction_rate' => $this->calculateCorrectionRate()
        ];
    }
    
    private function calculateCorrectionRate() {
        $total = count($this->searchHistory);
        if ($total === 0) return 0;
        
        $corrected = 0;
        foreach ($this->searchHistory as $search) {
            if ($search['was_corrected'] ?? false) {
                $corrected++;
            }
        }
        
        return round(($corrected / $total) * 100, 2);
    }
    
    public function enhanceSearchSuggestions($query, $books) {
        // Generate intelligent search suggestions based on the query and available books
        $suggestions = [];
        $query = strtolower(trim($query));
        
        // Basic suggestions
        if (strlen($query) < 3) {
            return $suggestions;
        }
        
        // First, try to correct the query
        $corrected = $this->correctMisspellings($query);
        if ($corrected !== $query) {
            $suggestions[] = $corrected;
        }
        
        // Find similar titles
        foreach ($books as $book) {
            $title = strtolower($book['title']);
            if (strpos($title, $query) !== false && $title !== $query) {
                $words = explode(' ', $title);
                foreach ($words as $word) {
                    if (strlen($word) > 3 && strpos($word, $query) !== false) {
                        $suggestions[] = $word;
                    }
                }
            }
        }
        
        // Add category-based suggestions
        $categories = array_unique(array_column($books, 'category'));
        foreach ($categories as $category) {
            $catLower = strtolower($category);
            if (strpos($catLower, $query) !== false) {
                $suggestions[] = $category;
            }
        }
        
        // Add author-based suggestions
        $authors = array_unique(array_column($books, 'author'));
        foreach ($authors as $author) {
            $authLower = strtolower($author);
            if (strpos($authLower, $query) !== false) {
                $suggestions[] = $author;
            }
        }
        
        // Add semantic suggestions
        foreach ($this->semanticPatterns as $category => $terms) {
            foreach ($terms as $term) {
                if (strpos($term, $query) !== false || similar_text($term, $query) > 3) {
                    $suggestions[] = $term;
                }
            }
        }
        
        // Remove duplicates and limit
        $suggestions = array_unique($suggestions);
        return array_slice($suggestions, 0, 10);
    }
    
    public function getSpellingSuggestions($query) {
        $query = strtolower(trim($query));
        $suggestions = [];
        
        // Check direct misspellings
        foreach ($this->misspellingCorrections as $correct => $misspellings) {
            if (in_array($query, $misspellings)) {
                $suggestions[] = $correct;
            }
        }
        
        // Check for close matches
        if (empty($suggestions)) {
            foreach ($this->misspellingCorrections as $correct => $misspellings) {
                $similarity = levenshtein($query, $correct);
                if ($similarity <= 3 && $similarity > 0) {
                    $suggestions[] = $correct;
                }
            }
        }
        
        return array_slice(array_unique($suggestions), 0, 5);
    }
}



// Handle AJAX requests
if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'elasticsearch_ai_mock.php') !== false) {
    if (isset($_GET['action']) && $_GET['action'] === 'search') {
        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (isset($input['query']) && isset($input['books'])) {
            $elasticAI = ElasticsearchAIMock::getInstance();
            
            // Perform intelligent search
            $results = $elasticAI->intelligentSearch($input['query'], $input['books'], $input['limit'] ?? 50);
            
            // Get insights
            $insights = $elasticAI->getSearchInsights();
            
            // Return JSON response
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'books' => $results,
                'corrected_query' => $elasticAI->correctMisspellings($input['query']),
                'insights' => $insights,
                'total_matches' => count($results)
            ]);
            exit;
        }
    }
}

?>