# Smart Quiz Portal Registration Tests

This directory contains automated tests for the Smart Quiz Portal registration functionality using TestNG and Selenium WebDriver.

## Test Coverage

The `RegisterTest.java` file includes comprehensive tests for:

- ✅ Valid student registration
- ✅ Valid teacher registration
- ✅ Empty field validation
- ✅ Invalid email format validation
- ✅ Password mismatch validation
- ✅ Duplicate username handling
- ✅ UI element verification
- ✅ Password strength requirements
- ✅ Navigation functionality

## Prerequisites

1. **Java 11 or higher** installed
2. **Maven** installed
3. **Chrome browser** installed
4. **Smart Quiz Portal** running locally or on a server

## Setup Instructions

### Option 1: Using Maven (Recommended)

1. Navigate to the testing directory:

   ```bash
   cd testing
   ```

2. Install dependencies:

   ```bash
   mvn clean install
   ```

3. Update the base URL in `RegisterTest.java`:

   ```java
   private String baseUrl = "http://localhost/smartquizportal"; // Update with your URL
   ```

4. Run the tests:
   ```bash
   mvn test
   ```

### Option 2: Using TestNG XML

1. Make sure all dependencies are in your classpath
2. Update the base URL in `RegisterTest.java`
3. Run using TestNG XML:
   ```bash
   java -cp "path/to/testng.jar:path/to/selenium.jar:." org.testng.TestNG testng.xml
   ```

### Option 3: Using IDE

1. Import the project into your IDE (IntelliJ IDEA, Eclipse, etc.)
2. Install TestNG plugin if not already installed
3. Update the base URL in `RegisterTest.java`
4. Right-click on `RegisterTest.java` and select "Run as TestNG Test"

## Configuration

### Base URL Configuration

Update the `baseUrl` variable in `RegisterTest.java` to match your application URL:

```java
private String baseUrl = "http://your-domain.com/smartquizportal";
```

### Browser Configuration

The tests are configured to run with Chrome by default. To use a different browser:

1. Update the WebDriver setup in the `setUp()` method
2. Add appropriate WebDriverManager setup:

   ```java
   // For Firefox
   WebDriverManager.firefoxdriver().setup();
   driver = new FirefoxDriver();

   // For Edge
   WebDriverManager.edgedriver().setup();
   driver = new EdgeDriver();
   ```

### Headless Mode

To run tests in headless mode (without opening browser window), uncomment this line in `setUp()`:

```java
options.addArguments("--headless");
```

## Test Data

The tests use dynamically generated test data to avoid conflicts:

- Usernames: `student_[timestamp]` or `teacher_[timestamp]`
- Emails: `student[timestamp]@test.com` or `teacher[timestamp]@test.com`
- Names: `Test Student [timestamp]` or `Test Teacher [timestamp]`

## Expected Form Fields

The tests expect the registration form to have these fields:

- `name` - Full name input field
- `username` - Username input field
- `email` - Email input field
- `password` - Password input field
- `confirm_password` - Confirm password input field
- `role_student` - Student role radio button with id="role_student"
- `role_teacher` - Teacher role radio button with id="role_teacher"
- Submit button with `type="submit"`

## Test Reports

After running tests with Maven, you can find:

- **Surefire Reports**: `target/surefire-reports/`
- **TestNG Reports**: `test-output/`

## Troubleshooting

### Common Issues

1. **ChromeDriver not found**

   - Solution: WebDriverManager should handle this automatically. If issues persist, manually download ChromeDriver and update PATH.

2. **Connection refused**

   - Solution: Ensure your Smart Quiz Portal application is running and accessible at the configured URL.

3. **Element not found**

   - Solution: Check that your registration form has the expected field names and structure.

4. **Tests failing due to timing**
   - Solution: Increase wait times in the `WebDriverWait` configuration.

### Debug Mode

To run tests with more verbose output:

```bash
mvn test -Dtestng.verbose=2
```

## Extending Tests

To add more test cases:

1. Add new `@Test` methods to `RegisterTest.java`
2. Use appropriate priority numbers to control execution order
3. Follow the existing naming convention: `test[Functionality][Scenario]()`
4. Add descriptive `description` parameter to `@Test` annotation

## Dependencies

- **TestNG 7.8.0** - Testing framework
- **Selenium WebDriver 4.15.0** - Browser automation
- **WebDriverManager 5.6.2** - Automatic driver management
- **ExtentReports 5.0.9** - Enhanced reporting
- **Apache Commons Lang3 3.13.0** - Utility functions

## Best Practices

1. Always use unique test data to avoid conflicts
2. Clean up test data after tests if needed
3. Use explicit waits instead of Thread.sleep()
4. Keep tests independent and atomic
5. Use descriptive test method names and descriptions
6. Group related tests using TestNG groups if needed

## Support

For issues or questions about these tests, please check:

1. The application is running and accessible
2. All form field names match the expected values
3. Browser and WebDriver versions are compatible
4. Network connectivity to the application
