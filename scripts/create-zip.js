const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

// Get version from package.json
const packageJson = require('../package.json');
const version = packageJson.version;

// Define paths
const rootDir = path.resolve(__dirname, '..');
const zipName = `carmo-woo-fees-v${version}.zip`;
const zipPath = path.join(rootDir, zipName);

console.log('🏗️  Building plugin...');

try {
    // Run build first
    execSync('npm run build', { stdio: 'inherit', cwd: rootDir });
    console.log('✅ Build completed successfully!\n');
} catch (error) {
    console.error('❌ Build failed! Cannot create zip file.');
    process.exit(1);
}

console.log('📦 Creating zip file...');

// Remove old zip if it exists
if (fs.existsSync(zipPath)) {
    fs.unlinkSync(zipPath);
    console.log('🗑️  Removed old zip file');
}

// Files and folders to include in the zip
const includeItems = [
    'carmo-woo-fees.php',
    'build',
];

// Create zip command
// Using -r for recursive, -q for quiet
const zipCommand = `cd "${rootDir}" && zip -r "${zipName}" ${includeItems.join(' ')} -x "*.DS_Store" "*__MACOSX*"`;

try {
    execSync(zipCommand, { stdio: 'pipe' });
    console.log(`✅ Zip file created: ${zipName}`);

    // Get file size
    const stats = fs.statSync(zipPath);
    const fileSizeInKB = (stats.size / 1024).toFixed(2);
    console.log(`📊 File size: ${fileSizeInKB} KB`);
    console.log(`📍 Location: ${zipPath}`);
    console.log('\n🎉 Ready to upload to WordPress!');
} catch (error) {
    console.error('❌ Error creating zip file:', error.message);
    process.exit(1);
}
