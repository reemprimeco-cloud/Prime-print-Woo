import UIKit
import WebKit
import Capacitor

@UIApplicationMain
class AppDelegate: UIResponder, UIApplicationDelegate {

    var window: UIWindow?

    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?) -> Bool {
        // The animated launch: Prime logo drawing in, then the slogan, held
        // until the site has loaded (Reem, 2026-10-02). Added on the next run
        // of the main loop so the window and the web view already exist.
        DispatchQueue.main.async { [weak self] in
            LaunchOverlay.show(in: self?.window)
        }
        return true
    }

    func applicationWillResignActive(_ application: UIApplication) {
        // Sent when the application is about to move from active to inactive state. This can occur for certain types of temporary interruptions (such as an incoming phone call or SMS message) or when the user quits the application and it begins the transition to the background state.
        // Use this method to pause ongoing tasks, disable timers, and invalidate graphics rendering callbacks. Games should use this method to pause the game.
    }

    func applicationDidEnterBackground(_ application: UIApplication) {
        // Use this method to release shared resources, save user data, invalidate timers, and store enough application state information to restore your application to its current state in case it is terminated later.
        // If your application supports background execution, this method is called instead of applicationWillTerminate: when the user quits.
    }

    func applicationWillEnterForeground(_ application: UIApplication) {
        // Called as part of the transition from the background to the active state; here you can undo many of the changes made on entering the background.
    }

    func applicationDidBecomeActive(_ application: UIApplication) {
        // Restart any tasks that were paused (or not yet started) while the application was inactive. If the application was previously in the background, optionally refresh the user interface.
    }

    func applicationWillTerminate(_ application: UIApplication) {
        // Called when the application is about to terminate. Save data if appropriate. See also applicationDidEnterBackground:.
    }

    func application(_ app: UIApplication, open url: URL, options: [UIApplication.OpenURLOptionsKey: Any] = [:]) -> Bool {
        // Called when the app was launched with a url. Feel free to add additional processing here,
        // but if you want the App API to support tracking app url opens, make sure to keep this call
        return ApplicationDelegateProxy.shared.application(app, open: url, options: options)
    }

    func application(_ application: UIApplication, continue userActivity: NSUserActivity, restorationHandler: @escaping ([UIUserActivityRestoring]?) -> Void) -> Bool {
        // Called when the app was launched with an activity, including Universal Links.
        // Feel free to add additional processing here, but if you want the App API to support
        // tracking app url opens, make sure to keep this call
        return ApplicationDelegateProxy.shared.application(application, continue: userActivity, restorationHandler: restorationHandler)
    }

}

// MARK: - Animated launch screen

/**
 The Prime Printing logo drawing itself in over the site while it loads, with
 the slogan underneath. White, like the site.

 Timeline (seconds from showing):
   0.00–0.95  PRIME wipes in from the left (a mask over the navy wordmark)
   0.80–1.25  the light-blue dot of the I springs in
   1.05–1.55  PRINTING CO. fades up
   1.45–2.05  "Quality with Integrity" fades up
   then the overlay stays until the web view has finished loading the site,
   never less than 2.6 s in total and never more than 6 s, and fades out.

 The three images (LaunchWord, LaunchDot, LaunchTag in Assets.xcassets) are
 cut from the logo file; the word and tagline share one canvas so they stack
 without any placement math, and the dot is placed by the ratios below, which
 the script that cut them printed.
 */
final class LaunchOverlay: UIView {

    private static let navy = UIColor(red: 16 / 255, green: 37 / 255, blue: 74 / 255, alpha: 1)
    private static let canvasAspect: CGFloat = 0.4437          // height / width of the logo canvas
    private static let dot: (x: CGFloat, y: CGFloat, w: CGFloat, h: CGFloat) = (0.4949, 0.0, 0.0435, 0.0951) // the dot inside that canvas
    private static let minimumSeconds: TimeInterval = 2.6
    private static let maximumSeconds: TimeInterval = 6.0

    private let word = UIImageView(image: UIImage(named: "LaunchWord"))
    private let dotView = UIImageView(image: UIImage(named: "LaunchDot"))
    private let tag = UIImageView(image: UIImage(named: "LaunchTag"))
    private let slogan = UILabel()
    private let wordMask = CALayer()

    private var shownAt = Date()
    private var loadObservation: NSKeyValueObservation?
    private var urlObservation: NSKeyValueObservation?
    private var dismissed = false

    static func show(in window: UIWindow?) {
        guard let window = window else { return }
        let overlay = LaunchOverlay(frame: window.bounds)
        overlay.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        window.addSubview(overlay)
        overlay.start(watching: LaunchOverlay.findWebView(in: window))
    }

    override init(frame: CGRect) {
        super.init(frame: frame)
        backgroundColor = .white
        isUserInteractionEnabled = true // swallow taps on the site underneath while it is covered

        let logoWidth = min(bounds.width * 0.62, 280)
        let logoHeight = logoWidth * LaunchOverlay.canvasAspect
        let gap: CGFloat = 26
        let sloganHeight: CGFloat = 22
        let total = logoHeight + gap + sloganHeight
        let top = (bounds.height - total) / 2
        let logoFrame = CGRect(x: (bounds.width - logoWidth) / 2, y: top, width: logoWidth, height: logoHeight)

        for view in [word, tag] {
            view.frame = logoFrame
            view.contentMode = .scaleAspectFit
            view.autoresizingMask = [.flexibleLeftMargin, .flexibleRightMargin, .flexibleTopMargin, .flexibleBottomMargin]
            addSubview(view)
        }
        dotView.frame = CGRect(
            x: logoFrame.minX + logoFrame.width * LaunchOverlay.dot.x,
            y: logoFrame.minY + logoFrame.height * LaunchOverlay.dot.y,
            width: logoFrame.width * LaunchOverlay.dot.w,
            height: logoFrame.height * LaunchOverlay.dot.h
        )
        dotView.contentMode = .scaleAspectFit
        dotView.autoresizingMask = word.autoresizingMask
        addSubview(dotView)

        slogan.text = "Quality with Integrity"
        slogan.textColor = LaunchOverlay.navy
        slogan.textAlignment = .center
        slogan.font = UIFont.systemFont(ofSize: 16, weight: .regular)
        if let text = slogan.text {
            slogan.attributedText = NSAttributedString(string: text, attributes: [.kern: 1.6, .font: slogan.font as Any, .foregroundColor: LaunchOverlay.navy])
        }
        slogan.frame = CGRect(x: 0, y: logoFrame.maxY + gap, width: bounds.width, height: sloganHeight)
        slogan.autoresizingMask = word.autoresizingMask
        addSubview(slogan)

        // Everything starts hidden; the animation reveals it.
        wordMask.backgroundColor = UIColor.black.cgColor
        wordMask.anchorPoint = CGPoint(x: 0, y: 0.5)
        wordMask.frame = CGRect(x: 0, y: 0, width: 0, height: logoHeight)
        word.layer.mask = wordMask
        dotView.transform = CGAffineTransform(scaleX: 0.01, y: 0.01)
        dotView.alpha = 0
        tag.alpha = 0
        tag.transform = CGAffineTransform(translationX: 0, y: 8)
        slogan.alpha = 0
        slogan.transform = CGAffineTransform(translationX: 0, y: 10)
    }

    required init?(coder: NSCoder) { fatalError("init(coder:) has not been implemented") }

    private func start(watching webView: WKWebView?) {
        shownAt = Date()

        if UIAccessibility.isReduceMotionEnabled {
            wordMask.frame.size.width = word.bounds.width
            dotView.transform = .identity
            dotView.alpha = 1
            tag.alpha = 1
            tag.transform = .identity
            slogan.alpha = 1
            slogan.transform = .identity
        } else {
            animate()
        }

        // Hide once the site has loaded, within the minimum/maximum window.
        if let webView = webView {
            loadObservation = webView.observe(\.isLoading, options: [.new]) { [weak self] view, _ in
                if !view.isLoading, view.url != nil { self?.dismissWhenAllowed() }
            }
            if !webView.isLoading, webView.url != nil { dismissWhenAllowed() }
        }
        DispatchQueue.main.asyncAfter(deadline: .now() + LaunchOverlay.maximumSeconds) { [weak self] in
            self?.dismiss()
        }
    }

    private func animate() {
        // 1. PRIME wipes in from the left.
        let wipe = CABasicAnimation(keyPath: "bounds.size.width")
        wipe.fromValue = 0
        wipe.toValue = word.bounds.width
        wipe.duration = 0.95
        wipe.timingFunction = CAMediaTimingFunction(name: .easeInEaseOut)
        wordMask.bounds.size.width = word.bounds.width
        wordMask.add(wipe, forKey: "wipe")

        // 2. The dot springs in.
        UIView.animate(withDuration: 0.45, delay: 0.8, usingSpringWithDamping: 0.55, initialSpringVelocity: 2, options: [], animations: {
            self.dotView.transform = .identity
            self.dotView.alpha = 1
        })

        // 3. PRINTING CO. fades up.
        UIView.animate(withDuration: 0.5, delay: 1.05, options: [.curveEaseOut], animations: {
            self.tag.alpha = 1
            self.tag.transform = .identity
        })

        // 4. The slogan fades up.
        UIView.animate(withDuration: 0.6, delay: 1.45, options: [.curveEaseOut], animations: {
            self.slogan.alpha = 1
            self.slogan.transform = .identity
        })
    }

    private func dismissWhenAllowed() {
        let elapsed = Date().timeIntervalSince(shownAt)
        let wait = max(0, LaunchOverlay.minimumSeconds - elapsed)
        DispatchQueue.main.asyncAfter(deadline: .now() + wait) { [weak self] in
            self?.dismiss()
        }
    }

    private func dismiss() {
        guard !dismissed else { return }
        dismissed = true
        loadObservation = nil
        urlObservation = nil
        UIView.animate(withDuration: 0.45, delay: 0, options: [.curveEaseInOut], animations: {
            self.alpha = 0
            self.transform = CGAffineTransform(scaleX: 1.03, y: 1.03)
        }, completion: { _ in
            self.removeFromSuperview()
        })
    }

    /// The Capacitor web view, wherever it sits in the hierarchy.
    private static func findWebView(in view: UIView) -> WKWebView? {
        if let webView = view as? WKWebView { return webView }
        for sub in view.subviews {
            if let found = findWebView(in: sub) { return found }
        }
        return nil
    }
}
